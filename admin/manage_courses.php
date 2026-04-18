<?php
// manage_courses.php - Admin manage courses and student access
require_once '../includes/auth.php';
requireLogin();
requireRole(['staff', 'admin']);

$conn = getDbConnection();

// Auto-add semester column if missing
$col_check = $conn->query("SHOW COLUMNS FROM vle_courses LIKE 'semester'");
if ($col_check && $col_check->num_rows === 0) {
    $conn->query("ALTER TABLE vle_courses ADD COLUMN semester ENUM('One','Two','Both') DEFAULT 'One' AFTER year_of_study");
}
// Auto-add applicable_years column if missing
$col_check2 = $conn->query("SHOW COLUMNS FROM vle_courses LIKE 'applicable_years'");
if ($col_check2 && $col_check2->num_rows === 0) {
    $conn->query("ALTER TABLE vle_courses ADD COLUMN applicable_years VARCHAR(50) DEFAULT NULL AFTER year_of_study");
}

// Auto-ensure required programs exist in programs table
$required_programs = [
    ['LSM', 'Logistics and Supply Chain Management', 'degree', 4],
    ['COD', 'Community Development', 'degree', 4],
    ['HRM', 'Human Resource Management', 'degree', 4],
    ['ICT', 'Information Technology', 'degree', 4],
    ['BIT', 'Information Technology', 'degree', 4],
    ['CS', 'Computer Science', 'degree', 4],
    ['BUS', 'Business Administration', 'degree', 4],
    ['ACC', 'Accounting and Finance', 'degree', 4],
    ['ECO', 'Economics', 'degree', 4],
    ['EDU', 'Education', 'degree', 4],
    ['MKT', 'Marketing', 'degree', 4],
    ['PAD', 'Public Administration', 'degree', 4],
    ['HSM', 'Health Systems Management', 'degree', 4],
];
$prog_table_check = $conn->query("SHOW TABLES LIKE 'programs'");
if ($prog_table_check && $prog_table_check->num_rows > 0) {
    foreach ($required_programs as $rp) {
        $check = $conn->prepare("SELECT program_id FROM programs WHERE program_code = ? OR program_name = ?");
        $check->bind_param("ss", $rp[0], $rp[1]);
        $check->execute();
        if ($check->get_result()->num_rows === 0) {
            $ins = $conn->prepare("INSERT IGNORE INTO programs (program_code, program_name, program_type, duration_years, is_active) VALUES (?, ?, ?, ?, 1)");
            $ins->bind_param("sssi", $rp[0], $rp[1], $rp[2], $rp[3]);
            $ins->execute();
            $ins->close();
        }
        $check->close();
    }
}

$success_message = '';
$error_message = '';

// AJAX: Inline update course program/year/semester
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['inline_update_course'])) {
    header('Content-Type: application/json');
    $course_id = (int)($_POST['course_id'] ?? 0);
    $field = $_POST['field'] ?? '';
    
    if ($course_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid course ID']);
        exit;
    }
    
    if ($field === 'program') {
        $value = trim($_POST['value'] ?? '');
        $stmt = $conn->prepare("UPDATE vle_courses SET program_of_study = ? WHERE course_id = ?");
        $stmt->bind_param("si", $value, $course_id);
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Program updated']);
        } else {
            echo json_encode(['success' => false, 'message' => $conn->error]);
        }
        $stmt->close();
    } elseif ($field === 'year_semester') {
        $year = (int)($_POST['year'] ?? 0);
        $semester = in_array($_POST['semester'] ?? '', ['One', 'Two', 'Both']) ? $_POST['semester'] : 'One';
        $applicable_years = trim($_POST['applicable_years'] ?? '');
        $applicable_years = !empty($applicable_years) ? $applicable_years : null;
        $stmt = $conn->prepare("UPDATE vle_courses SET year_of_study = ?, applicable_years = ?, semester = ? WHERE course_id = ?");
        $stmt->bind_param("issi", $year, $applicable_years, $semester, $course_id);
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Year/Semester updated']);
        } else {
            echo json_encode(['success' => false, 'message' => $conn->error]);
        }
        $stmt->close();
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid field']);
    }
    exit;
}

// Check for session success message (from edit_course.php redirect)
if (isset($_SESSION['success_message'])) {
    $success_message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

// Handle template download
if (isset($_GET['download_template'])) {
    header('Content-Type: text/csv');
    
    // Build list of available programs for instructions
    $prog_list = [];
    $prog_result = $conn->query("SELECT program_name FROM programs WHERE is_active = 1 ORDER BY program_name");
    if ($prog_result) {
        while ($p = $prog_result->fetch_assoc()) {
            $prog_list[] = $p['program_name'];
        }
    }
    
    if (isset($_GET['export_existing'])) {
        // Export all existing courses WITH their associated programs
        header('Content-Disposition: attachment; filename="courses_export.csv"');
        
        $output = fopen('php://output', 'w');
        fputcsv($output, ['Course Code', 'Course Name', 'Description', 'Program of Study', 'Year of Study', 'Semester', 'Total Weeks', 'Lecturer ID', 'Associated Programs']);
        
        $courses_result = $conn->query("SELECT c.course_id, c.course_code, c.course_name, c.description, c.program_of_study, c.year_of_study, c.semester, c.total_weeks, c.lecturer_id FROM vle_courses c ORDER BY c.course_code");
        if ($courses_result) {
            while ($c = $courses_result->fetch_assoc()) {
                // Get associated programs for this course
                $assoc_progs = [];
                $ap_result = $conn->query("SELECT p.program_name FROM course_programs cp INNER JOIN programs p ON cp.program_id = p.program_id WHERE cp.course_id = " . (int)$c['course_id'] . " ORDER BY p.program_name");
                if ($ap_result) {
                    while ($ap = $ap_result->fetch_assoc()) {
                        $assoc_progs[] = $ap['program_name'];
                    }
                }
                
                fputcsv($output, [
                    $c['course_code'],
                    $c['course_name'],
                    $c['description'] ?? '',
                    $c['program_of_study'] ?? '',
                    $c['year_of_study'] ?? 1,
                    $c['semester'] ?? 'One',
                    $c['total_weeks'] ?? 16,
                    $c['lecturer_id'] ?? '',
                    implode(';', $assoc_progs)
                ]);
            }
        }
        fclose($output);
    } else {
        // Download blank template with sample rows
        header('Content-Disposition: attachment; filename="courses_template.csv"');
        
        echo "Course Code,Course Name,Description,Program of Study,Year of Study,Semester,Total Weeks,Lecturer ID,Associated Programs\n";
        echo "CS101,Introduction to Programming,Basic programming concepts,Computer Science,1,One,16,,\"Information Technology;Business Administration\"\n";
        echo "CS201,Data Structures,Advanced data structures,Computer Science,2,One,16,,\n";
        echo "BUS101,Business Fundamentals,Introduction to business,Business Administration,1,Two,16,,\"Accounting and Finance;Marketing;Economics\"\n";
        echo "\n";
        echo "# INSTRUCTIONS:\n";
        echo "# Associated Programs column: semicolon-separated list of additional programs that also study this course\n";
        echo "# Available programs: " . implode('; ', $prog_list) . "\n";
        echo "# Leave Associated Programs empty if the course belongs only to its primary Program of Study\n";
        echo "# Re-uploading existing course codes will UPDATE the course and REPLACE its associated programs\n";
    }
    exit;
}

// Handle template upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['course_template'])) {
    $file = $_FILES['course_template'];
    
    if ($file['error'] === UPLOAD_ERR_OK) {
        $file_path = $file['tmp_name'];
        $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        if ($file_extension === 'csv') {
            $handle = fopen($file_path, 'r');
            
            if ($handle !== false) {
                // Skip header row
                $header = fgetcsv($handle);
                
                $imported_count = 0;
                $updated_count = 0;
                $error_rows = [];
                $row_number = 1;
                
                while (($data = fgetcsv($handle)) !== false) {
                    $row_number++;
                    
                    // Skip comment/instruction rows
                    if (isset($data[0]) && strpos(trim($data[0]), '#') === 0) {
                        continue;
                    }
                    
                    if (count($data) < 7) {
                        $error_rows[] = "Row $row_number: Insufficient columns";
                        continue;
                    }
                    
                    $course_code = trim($data[0]);
                    $course_name = trim($data[1]);
                    $description = trim($data[2]);
                    $program = trim($data[3]);
                    $year_of_study = isset($data[4]) ? (int)trim($data[4]) : 1;
                    $semester = isset($data[5]) ? trim($data[5]) : 'One';
                    $semester = in_array($semester, ['One', 'Two', 'Both']) ? $semester : 'One';
                    $total_weeks = isset($data[6]) ? (int)trim($data[6]) : 16;
                    $lecturer_id = isset($data[7]) && !empty(trim($data[7])) ? (int)trim($data[7]) : NULL;
                    $associated_programs_str = isset($data[8]) ? trim($data[8]) : '';
                    
                    if (empty($course_code) || empty($course_name)) {
                        $error_rows[] = "Row $row_number: Course code and name are required";
                        continue;
                    }
                    
                    // Check if course code already exists
                    $check_stmt = $conn->prepare("SELECT course_id FROM vle_courses WHERE course_code = ?");
                    $check_stmt->bind_param("s", $course_code);
                    $check_stmt->execute();
                    $check_result = $check_stmt->get_result();
                    $existing_course = $check_result->fetch_assoc();
                    $check_stmt->close();
                    
                    // Validate lecturer_id exists in lecturers table (FK constraint)
                    if ($lecturer_id !== NULL) {
                        $lec_check = $conn->prepare("SELECT lecturer_id FROM lecturers WHERE lecturer_id = ?");
                        $lec_check->bind_param("i", $lecturer_id);
                        $lec_check->execute();
                        if ($lec_check->get_result()->num_rows === 0) {
                            $lecturer_id = NULL;
                        }
                        $lec_check->close();
                    }
                    
                    if ($existing_course) {
                        // UPDATE existing course details
                        $existing_id = (int)$existing_course['course_id'];
                        $upd = $conn->prepare("UPDATE vle_courses SET course_name = ?, description = ?, program_of_study = ?, year_of_study = ?, semester = ?, total_weeks = ?, lecturer_id = ? WHERE course_id = ?");
                        $upd->bind_param("sssisiii", $course_name, $description, $program, $year_of_study, $semester, $total_weeks, $lecturer_id, $existing_id);
                        
                        if ($upd->execute()) {
                            $updated_count++;
                            
                            // Update associated programs — replace existing with what's in the CSV
                            if (!empty($associated_programs_str)) {
                                // Clear existing associations first, then insert from CSV
                                $conn->query("DELETE FROM course_programs WHERE course_id = $existing_id");
                                
                                $assoc_names = array_map('trim', explode(';', $associated_programs_str));
                                $assoc_insert = $conn->prepare("INSERT IGNORE INTO course_programs (course_id, program_id) VALUES (?, ?)");
                                foreach ($assoc_names as $assoc_name) {
                                    if (empty($assoc_name)) continue;
                                    $prog_lookup = $conn->prepare("SELECT program_id FROM programs WHERE program_name = ? AND is_active = 1");
                                    $prog_lookup->bind_param("s", $assoc_name);
                                    $prog_lookup->execute();
                                    $prog_result = $prog_lookup->get_result();
                                    if ($prog_row = $prog_result->fetch_assoc()) {
                                        $assoc_insert->bind_param("ii", $existing_id, $prog_row['program_id']);
                                        $assoc_insert->execute();
                                    }
                                    $prog_lookup->close();
                                }
                                $assoc_insert->close();
                            }
                            // If Associated Programs column is empty, existing associations are kept unchanged
                        } else {
                            $error_rows[] = "Row $row_number: Error updating '$course_code' - " . $upd->error;
                        }
                        $upd->close();
                    } else {
                        // INSERT new course
                        $stmt = $conn->prepare("INSERT INTO vle_courses (course_code, course_name, description, lecturer_id, total_weeks, program_of_study, year_of_study, semester) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                        $stmt->bind_param("sssiisis", $course_code, $course_name, $description, $lecturer_id, $total_weeks, $program, $year_of_study, $semester);
                    
                        if ($stmt->execute()) {
                            $new_course_id = $conn->insert_id;
                            $imported_count++;
                        
                            // Handle associated programs from CSV
                            if (!empty($associated_programs_str)) {
                                $assoc_names = array_map('trim', explode(';', $associated_programs_str));
                                $assoc_insert = $conn->prepare("INSERT IGNORE INTO course_programs (course_id, program_id) VALUES (?, ?)");
                                foreach ($assoc_names as $assoc_name) {
                                    if (empty($assoc_name)) continue;
                                    $prog_lookup = $conn->prepare("SELECT program_id FROM programs WHERE program_name = ? AND is_active = 1");
                                    $prog_lookup->bind_param("s", $assoc_name);
                                    $prog_lookup->execute();
                                    $prog_result = $prog_lookup->get_result();
                                    if ($prog_row = $prog_result->fetch_assoc()) {
                                        $assoc_insert->bind_param("ii", $new_course_id, $prog_row['program_id']);
                                        $assoc_insert->execute();
                                    }
                                    $prog_lookup->close();
                                }
                                $assoc_insert->close();
                            }
                        } else {
                            $error_rows[] = "Row $row_number: Database error - " . $stmt->error;
                        }
                        $stmt->close();
                    }
                }
                
                fclose($handle);
                
                // Build success message
                $msg_parts = [];
                if ($imported_count > 0) $msg_parts[] = "imported $imported_count new course(s)";
                if ($updated_count > 0) $msg_parts[] = "updated $updated_count existing course(s)";
                if (!empty($msg_parts)) {
                    $success_message = "Successfully " . implode(" and ", $msg_parts) . "!";
                }
                
                if (!empty($error_rows)) {
                    $error_message = "Import completed with errors:\n" . implode("\n", $error_rows);
                }
                
                if ($imported_count === 0 && empty($error_rows)) {
                    $error_message = "No valid courses found in the template.";
                }
            } else {
                $error_message = "Error opening uploaded file.";
            }
        } else {
            $error_message = "Invalid file format. Please upload a CSV file.";
        }
    } else {
        $error_message = "Error uploading file. Please try again.";
    }
}

// Handle course creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_course'])) {
    $course_code = $_POST['course_code'];
    $course_name = $_POST['course_name'];
    $description = $_POST['description'];
    $program = $_POST['program'];
    
    // Handle multi-year selection
    $selected_years = $_POST['years'] ?? [];
    $selected_years = array_filter(array_map('intval', $selected_years), function($y) { return $y >= 1 && $y <= 4; });
    sort($selected_years);
    $year_of_study = !empty($selected_years) ? $selected_years[0] : (int)($_POST['year_of_study'] ?? 1);
    $additional_years = array_filter($selected_years, function($y) use ($year_of_study) { return $y != $year_of_study; });
    $applicable_years = !empty($additional_years) ? implode(',', $additional_years) : null;
    
    $semester = $_POST['semester'];
    $semester = in_array($semester, ['One', 'Two', 'Both']) ? $semester : 'One';
    $lecturer_id = !empty($_POST['lecturer_id']) ? (int)$_POST['lecturer_id'] : NULL;
    $total_weeks = $_POST['total_weeks'];
    
    // Validate lecturer_id exists in lecturers table (FK constraint)
    if ($lecturer_id !== NULL) {
        $lec_check = $conn->prepare("SELECT lecturer_id FROM lecturers WHERE lecturer_id = ?");
        $lec_check->bind_param("i", $lecturer_id);
        $lec_check->execute();
        if ($lec_check->get_result()->num_rows === 0) {
            $lecturer_id = NULL;
        }
        $lec_check->close();
    }
    
    $stmt = $conn->prepare("INSERT INTO vle_courses (course_code, course_name, description, lecturer_id, total_weeks, program_of_study, year_of_study, applicable_years, semester) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("sssiisiss", $course_code, $course_name, $description, $lecturer_id, $total_weeks, $program, $year_of_study, $applicable_years, $semester);
    
    if ($stmt->execute()) {
        $new_course_id = $conn->insert_id;
        
        // Handle additional program associations
        $additional_programs = $_POST['additional_programs'] ?? [];
        if (!empty($additional_programs)) {
            $assoc_stmt = $conn->prepare("INSERT IGNORE INTO course_programs (course_id, program_id) VALUES (?, ?)");
            foreach ($additional_programs as $prog_id) {
                $prog_id = (int)$prog_id;
                if ($prog_id > 0) {
                    $assoc_stmt->bind_param("ii", $new_course_id, $prog_id);
                    $assoc_stmt->execute();
                }
            }
            $assoc_stmt->close();
        }
        
        $success_message = "Course created successfully!";
    } else {
        $error_message = "Error creating course: " . $conn->error;
    }
    $stmt->close();
}

// Handle course deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_course'])) {
    $course_id = (int)$_POST['course_id'];
    
    // Temporarily disable foreign key checks to allow cascading delete
    $conn->query("SET FOREIGN_KEY_CHECKS = 0");
    
    // Find all tables with course_id column and delete related records
    $db_name = $conn->query("SELECT DATABASE()")->fetch_row()[0];
    $tables_query = $conn->query("
        SELECT TABLE_NAME 
        FROM INFORMATION_SCHEMA.COLUMNS 
        WHERE TABLE_SCHEMA = '$db_name' 
        AND COLUMN_NAME = 'course_id' 
        AND TABLE_NAME != 'vle_courses'
    ");
    
    while ($table_row = $tables_query->fetch_assoc()) {
        $table = $table_row['TABLE_NAME'];
        $stmt = $conn->prepare("DELETE FROM `$table` WHERE course_id = ?");
        if ($stmt) {
            $stmt->bind_param("i", $course_id);
            $stmt->execute();
            $stmt->close();
        }
    }
    
    // Delete the course
    $stmt = $conn->prepare("DELETE FROM vle_courses WHERE course_id = ?");
    $stmt->bind_param("i", $course_id);
    
    if ($stmt->execute()) {
        $success_message = "Course deleted successfully!";
    } else {
        $error_message = "Error deleting course: " . $conn->error;
    }
    $stmt->close();
    
    // Re-enable foreign key checks
    $conn->query("SET FOREIGN_KEY_CHECKS = 1");
}

// Handle bulk student enrollment by program
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['enroll_by_program'])) {
    $course_id = (int)$_POST['course_id'];
    $program = $_POST['program_filter'];
    $year = $_POST['year_filter'];
    $semester = $_POST['semester_filter'];
    
    // Get students by program, year, and semester
    $where_conditions = [];
    $params = [];
    $types = "";
    
    if (!empty($program)) {
        $where_conditions[] = "program = ?";
        $params[] = $program;
        $types .= "s";
    }
    if (!empty($year)) {
        $where_conditions[] = "year_of_study = ?";
        $params[] = $year;
        $types .= "i";
    }
    if (!empty($semester)) {
        $where_conditions[] = "semester = ?";
        $params[] = $semester;
        $types .= "s";
    }
    
    $where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";
    
    $stmt = $conn->prepare("SELECT student_id FROM students $where_clause");
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    
    $enrolled_count = 0;
    while ($row = $result->fetch_assoc()) {
        $student_id = $row['student_id'];
        
        // Check if already enrolled
        $check_stmt = $conn->prepare("SELECT enrollment_id FROM vle_enrollments WHERE course_id = ? AND student_id = ?");
        $check_stmt->bind_param("is", $course_id, $student_id);
        $check_stmt->execute();
        
        if ($check_stmt->get_result()->num_rows === 0) {
            $enroll_stmt = $conn->prepare("INSERT INTO vle_enrollments (course_id, student_id) VALUES (?, ?)");
            $enroll_stmt->bind_param("is", $course_id, $student_id);
            if ($enroll_stmt->execute()) {
                $enrolled_count++;
            }
            $enroll_stmt->close();
        }
        $check_stmt->close();
    }
    $stmt->close();
    
    $success_message = "$enrolled_count student(s) enrolled successfully!";
}

// Handle individual student enrollment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['enroll_student'])) {
    $course_id = (int)$_POST['course_id'];
    $student_id = $_POST['student_id'];

    // Check if already enrolled
    $stmt = $conn->prepare("SELECT * FROM vle_enrollments WHERE course_id = ? AND student_id = ?");
    $stmt->bind_param("is", $course_id, $student_id);
    $stmt->execute();
    if ($stmt->get_result()->num_rows === 0) {
        $stmt = $conn->prepare("INSERT INTO vle_enrollments (course_id, student_id) VALUES (?, ?)");
        $stmt->bind_param("is", $course_id, $student_id);
        if ($stmt->execute()) {
            $success_message = "Student enrolled successfully!";
        } else {
            $error_message = "Error enrolling student: " . $conn->error;
        }
    } else {
        $error_message = "Student is already enrolled in this course!";
    }
    $stmt->close();
}

// Handle bulk student allocation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_allocate_students'])) {
    $course_id = (int)$_POST['allocate_course_id'];
    $student_ids = $_POST['student_ids'] ?? [];
    
    if (empty($student_ids)) {
        $error_message = "Please select at least one student to allocate.";
    } else {
        $success_count = 0;
        $already_enrolled = 0;
        
        foreach ($student_ids as $student_id) {
            $student_id = trim($student_id);
            
            // Check if already enrolled
            $check = $conn->prepare("SELECT enrollment_id FROM vle_enrollments WHERE student_id = ? AND course_id = ?");
            $check->bind_param("si", $student_id, $course_id);
            $check->execute();
            $result = $check->get_result();
            
            if ($result->num_rows > 0) {
                $already_enrolled++;
            } else {
                // Allocate student to course
                $stmt = $conn->prepare("INSERT INTO vle_enrollments (student_id, course_id, enrollment_date) VALUES (?, ?, NOW())");
                $stmt->bind_param("si", $student_id, $course_id);
                if ($stmt->execute()) {
                    $success_count++;
                }
                $stmt->close();
            }
            $check->close();
        }
        
        if ($success_count > 0) {
            $success_message = "$success_count student(s) allocated successfully!";
            if ($already_enrolled > 0) {
                $success_message .= " ($already_enrolled already enrolled)";
            }
        } else if ($already_enrolled > 0) {
            $error_message = "All selected students are already enrolled in this course.";
        }
    }
}

// Handle student unenrollment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['unenroll_student'])) {
    $enrollment_id = (int)$_POST['enrollment_id'];
    
    $stmt = $conn->prepare("DELETE FROM vle_enrollments WHERE enrollment_id = ?");
    $stmt->bind_param("i", $enrollment_id);
    if ($stmt->execute()) {
        $success_message = "Student unenrolled successfully!";
    } else {
        $error_message = "Error unenrolling student: " . $conn->error;
    }
    $stmt->close();
}

// Get all students with program info
$students = [];
$result = $conn->query("SELECT student_id, full_name, program, year_of_study FROM students ORDER BY full_name");
while ($row = $result->fetch_assoc()) {
    $students[] = $row;
}

// Get all lecturers
$lecturers = [];
$result = $conn->query("SELECT lecturer_id, full_name FROM lecturers ORDER BY full_name");
while ($row = $result->fetch_assoc()) {
    $lecturers[] = $row;
}

// Get distinct programs from programs table
$programs = [];
$result = $conn->query("SELECT program_name FROM programs WHERE is_active = 1 ORDER BY program_name");
while ($row = $result->fetch_assoc()) {
    $programs[] = $row['program_name'];
}

// Get all programs with IDs for associated programs multi-select
$all_programs = [];
$result = $conn->query("SELECT program_id, program_code, program_name FROM programs WHERE is_active = 1 ORDER BY program_name");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $all_programs[] = $row;
    }
}

// Check if program_of_study and year_of_study columns exist in vle_courses
$columns_exist = false;
$result = $conn->query("SHOW COLUMNS FROM vle_courses LIKE 'program_of_study'");
if ($result->num_rows === 0) {
    // Add missing columns
    $conn->query("ALTER TABLE vle_courses ADD COLUMN program_of_study VARCHAR(100) AFTER lecturer_id");
    $conn->query("ALTER TABLE vle_courses ADD COLUMN year_of_study INT AFTER program_of_study");
}

// Get all courses with lecturer info and enrolled students
$courses = [];
$result = $conn->query("
    SELECT vc.*, l.full_name as lecturer_name,
           COUNT(ve.enrollment_id) as enrolled_students
    FROM vle_courses vc
    LEFT JOIN lecturers l ON vc.lecturer_id = l.lecturer_id
    LEFT JOIN vle_enrollments ve ON vc.course_id = ve.course_id
    GROUP BY vc.course_id
    ORDER BY vc.created_date DESC
");
while ($row = $result->fetch_assoc()) {
    $courses[] = $row;
}

// Note: Don't close $conn here - header_nav.php needs it for getCurrentUser()
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Courses - Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
    <style>
        .card-header-courses {
            background: var(--vle-gradient-primary) !important;
            border: none;
            color: white;
        }
        .card-header-template {
            background: var(--vle-gradient-success) !important;
            border: none;
            color: white;
        }
        .quick-filter-bar {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border: 1px solid #dee2e6;
            border-radius: 12px;
            padding: 1.25rem;
        }

        .inline-program-select, .inline-year-select {
            transition: border-color 0.3s, box-shadow 0.3s;
            cursor: pointer;
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
        }
        .inline-program-select:hover, .inline-year-select:hover {
            border-color: #1e3c72;
            background-color: #fff;
        }
        .inline-program-select:focus, .inline-year-select:focus {
            border-color: #2a5298;
            box-shadow: 0 0 0 3px rgba(42,82,152,0.2);
            background-color: #fff;
        }
        .active-filter-display {
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            color: #fff;
            border-radius: 10px;
            padding: 0.75rem 1.25rem;
            display: none;
        }
        .active-filter-display.show {
            display: flex;
        }
    </style>
</head>
<body>
    <?php 
    $breadcrumbs = [['title' => 'Manage Courses']];
    include 'header_nav.php'; 
    ?>

    <div class="vle-content">
        <!-- Page Header -->
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
            <div>
                <h2 class="vle-page-title"><i class="bi bi-book me-2"></i>Manage Courses</h2>
                <p class="text-muted mb-0">Create courses, manage enrollments, and assign students by program</p>
            </div>
            <div class="btn-group" role="group">
                <a href="?download_template" class="btn btn-success">
                    <i class="bi bi-download"></i> Blank Template
                </a>
                <a href="?download_template&export_existing" class="btn btn-outline-success">
                    <i class="bi bi-file-earmark-spreadsheet"></i> Export Courses
                </a>
                <button class="btn btn-info" data-bs-toggle="modal" data-bs-target="#uploadTemplateModal">
                    <i class="bi bi-upload"></i> Upload Template
                </button>
                <button class="btn btn-warning" data-bs-toggle="modal" data-bs-target="#addCourseModal">
                    <i class="bi bi-plus-circle"></i> Add New Course
                </button>
            </div>
        </div>
        
        <!-- Filter Bar -->
        <div class="quick-filter-bar mb-4">
            <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
                <h6 class="mb-0 fw-bold"><i class="bi bi-funnel me-2"></i>Filter Courses</h6>
                <button class="btn btn-sm btn-outline-secondary" onclick="clearAllFilters()">
                    <i class="bi bi-x-circle me-1"></i>Clear All
                </button>
            </div>
            <div class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label fw-semibold mb-1" style="font-size:0.85rem;"><i class="bi bi-diagram-3 me-1"></i>Program</label>
                    <select class="form-select" id="filterProgram" onchange="applyFilters()">
                        <option value="">All Programs</option>
                        <?php foreach ($programs as $prog): ?>
                            <option value="<?= htmlspecialchars($prog) ?>"><?= htmlspecialchars($prog) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold mb-1" style="font-size:0.85rem;"><i class="bi bi-calendar3 me-1"></i>Year of Study</label>
                    <select class="form-select" id="filterYear" onchange="applyFilters()">
                        <option value="">All Years</option>
                        <option value="1">Year 1</option>
                        <option value="2">Year 2</option>
                        <option value="3">Year 3</option>
                        <option value="4">Year 4</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold mb-1" style="font-size:0.85rem;"><i class="bi bi-calendar-week me-1"></i>Semester</label>
                    <select class="form-select" id="filterSemester" onchange="applyFilters()">
                        <option value="">All Semesters</option>
                        <option value="One">Semester One</option>
                        <option value="Two">Semester Two</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold mb-1" style="font-size:0.85rem;"><i class="bi bi-search me-1"></i>Search</label>
                    <input type="text" class="form-control" id="filterSearch" placeholder="Code or name..." oninput="applyFilters()">
                </div>
            </div>
        </div>

        <!-- Active Filter Display -->
        <div class="active-filter-display mb-3" id="activeFilterDisplay">
            <div class="d-flex flex-wrap align-items-center justify-content-between w-100">
                <div>
                    <i class="bi bi-funnel-fill me-2"></i>
                    <span class="fw-bold" id="activeFilterText">Showing all courses</span>
                </div>
                <div>
                    <span class="badge bg-light text-dark me-2" id="activeFilterCount">0 courses</span>
                    <button class="btn btn-sm btn-outline-light" onclick="clearAllFilters()">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </div>
            </div>
        </div>

        <!-- Success/Error Messages -->
        <?php if ($success_message): ?>
            <div class="alert vle-alert-success alert-dismissible fade show" role="alert">
                <i class="bi bi-check-circle"></i> <?php echo $success_message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        <?php if ($error_message): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-triangle"></i> <?php echo $error_message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Courses List -->
        <div class="card shadow-sm">
            <div class="card-header bg-warning text-dark">
                <h5 class="mb-0"><i class="bi bi-list-ul"></i> All Courses (<?php echo count($courses); ?>)</h5>
            </div>

            <div class="card-body p-0">
                <?php if (empty($courses)): ?>
                    <div class="text-center text-muted py-5">
                        <i class="bi bi-inbox" style="font-size: 3rem;"></i>
                        <p class="mt-3">No courses found. Click "Add New Course" to create one.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover table-striped mb-0" id="coursesTable">
                            <thead class="table-dark">
                                <tr>
                                    <th class="sortable" data-sort="code" style="cursor: pointer;">Course Code <i class="bi bi-arrow-down-up ms-1"></i></th>
                                    <th class="sortable" data-sort="name" style="cursor: pointer;">Course Name <i class="bi bi-arrow-down-up ms-1"></i></th>
                                    <th class="sortable" data-sort="program" style="cursor: pointer;">Program <i class="bi bi-arrow-down-up ms-1"></i></th>
                                    <th>Year</th>
                                    <th>Lecturer</th>
                                    <th>Enrolled</th>
                                    <th>Weeks</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="coursesTableBody">
                                <?php foreach ($courses as $course): ?>
                                    <tr data-program="<?php echo htmlspecialchars($course['program_of_study'] ?? ''); ?>" data-code="<?php echo htmlspecialchars($course['course_code']); ?>" data-name="<?php echo htmlspecialchars($course['course_name']); ?>" data-year="<?php echo (int)($course['year_of_study'] ?? 0); ?>" data-semester="<?php echo htmlspecialchars($course['semester'] ?? ''); ?>">
                                        <td><strong class="text-primary"><?php echo htmlspecialchars($course['course_code']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($course['course_name']); ?></td>
                                        <td>
                                            <select class="form-select form-select-sm inline-program-select" 
                                                    data-course-id="<?php echo $course['course_id']; ?>"
                                                    onchange="inlineUpdateProgram(this)"
                                                    style="min-width:160px;font-size:0.78rem;padding:2px 24px 2px 6px;">
                                                <option value="" <?php echo empty($course['program_of_study']) || $course['program_of_study'] === 'General Department' ? 'selected' : ''; ?>>General Department</option>
                                                <?php foreach ($programs as $prog): ?>
                                                    <option value="<?php echo htmlspecialchars($prog); ?>" <?php echo ($course['program_of_study'] ?? '') === $prog ? 'selected' : ''; ?>><?php echo htmlspecialchars($prog); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <td>
                                            <?php
                                            // Build display for year/semester with multi-year support
                                            $cy = (int)($course['year_of_study'] ?? 0);
                                            $cs = $course['semester'] ?? 'One';
                                            $ay = $course['applicable_years'] ?? '';
                                            $all_years = $cy > 0 ? [$cy] : [];
                                            if (!empty($ay)) {
                                                foreach (explode(',', $ay) as $ey) {
                                                    $ey = (int)trim($ey);
                                                    if ($ey > 0 && !in_array($ey, $all_years)) $all_years[] = $ey;
                                                }
                                                sort($all_years);
                                            }
                                            $year_display = !empty($all_years) ? implode(',', array_map(fn($y) => "Y$y", $all_years)) : 'N/A';
                                            $sem_labels = ['One' => 'S1', 'Two' => 'S2', 'Both' => 'S1&2'];
                                            $sem_display = $sem_labels[$cs] ?? 'S1';
                                            ?>
                                            <a href="edit_course.php?id=<?php echo $course['course_id']; ?>" 
                                               class="btn btn-sm btn-outline-primary" style="font-size:0.78rem;white-space:nowrap;"
                                               title="Click to edit year/semester association">
                                                <?php echo $year_display; ?> <?php echo $sem_display; ?>
                                                <?php if (count($all_years) > 1 || $cs === 'Both'): ?>
                                                    <i class="bi bi-layers-fill text-warning ms-1" title="Multi-year/semester"></i>
                                                <?php endif; ?>
                                                <i class="bi bi-pencil-square ms-1"></i>
                                            </a>
                                        </td>
                                        <td><?php echo htmlspecialchars($course['lecturer_name'] ?? 'Not assigned'); ?></td>
                                        <td>
                                            <span class="badge bg-primary"><?php echo $course['enrolled_students']; ?> students</span>
                                        </td>
                                        <td><?php echo $course['total_weeks']; ?></td>
                                        <td>
                                            <span class="badge bg-<?php echo $course['is_active'] ? 'success' : 'secondary'; ?>">
                                                <?php echo $course['is_active'] ? 'Active' : 'Inactive'; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="btn-group" role="group">
                                                <!-- Edit/Delete Dropdown -->
                                                <div class="btn-group" role="group">
                                                    <button type="button" class="btn btn-sm btn-secondary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false" title="Edit or Delete Course">
                                                        <i class="bi bi-gear"></i>
                                                    </button>
                                                    <ul class="dropdown-menu">
                                                        <li>
                                                            <a class="dropdown-item" href="edit_course.php?id=<?php echo $course['course_id']; ?>">
                                                                <i class="bi bi-pencil-square text-primary me-2"></i>Edit Course
                                                            </a>
                                                        </li>
                                                        <li><hr class="dropdown-divider"></li>
                                                        <li>
                                                            <a class="dropdown-item text-danger" href="#" onclick="confirmDeleteCourse(<?php echo $course['course_id']; ?>, '<?php echo addslashes($course['course_name']); ?>', <?php echo $course['enrolled_students']; ?>)">
                                                                <i class="bi bi-trash me-2"></i>Delete Course
                                                            </a>
                                                        </li>
                                                    </ul>
                                                </div>
                                                <button class="btn btn-sm btn-warning" 
                                                        onclick="openAllocateStudentsModal(<?php echo $course['course_id']; ?>, '<?php echo addslashes($course['course_code']); ?>', '<?php echo addslashes($course['course_name']); ?>', '<?php echo addslashes($course['program_of_study'] ?? ''); ?>', <?php echo (!empty($course['year_of_study']) && $course['year_of_study'] > 0) ? $course['year_of_study'] : "''"; ?>)"
                                                        title="Allocate students to access course content">
                                                    <i class="bi bi-person-plus-fill"></i> Allocate
                                                </button>
                                                <button class="btn btn-sm btn-success" 
                                                        onclick="openEnrollByProgramModal(<?php echo $course['course_id']; ?>, '<?php echo addslashes($course['course_name']); ?>')">
                                                    <i class="bi bi-people-fill"></i> By Program
                                                </button>
                                                <button class="btn btn-sm btn-primary" 
                                                        onclick="openEnrollModal(<?php echo $course['course_id']; ?>, '<?php echo addslashes($course['course_name']); ?>')">
                                                    <i class="bi bi-person-plus"></i> Individual
                                                </button>
                                                <button class="btn btn-sm btn-info" 
                                                        onclick="viewEnrolledStudents(<?php echo $course['course_id']; ?>, '<?php echo addslashes($course['course_name']); ?>')">
                                                    <i class="bi bi-eye"></i> View
                                                </button>
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
    </div>

    <!-- Upload Template Modal -->
    <div class="modal fade" id="uploadTemplateModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title"><i class="bi bi-upload"></i> Upload Course Template</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" enctype="multipart/form-data">
                    <div class="modal-body">
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle"></i> <strong>Instructions:</strong>
                            <ol class="mb-0 mt-2">
                                <li>Click "Blank Template" for a new CSV, or "Export Courses" to get existing courses</li>
                                <li>Fill in or edit the course details in the CSV</li>
                                <li>Upload the completed template below</li>
                            </ol>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold"><i class="bi bi-file-earmark-spreadsheet"></i> Select CSV File *</label>
                            <input type="file" class="form-control" name="course_template" accept=".csv" required>
                        </div>
                        
                        <div class="alert alert-warning">
                            <i class="bi bi-exclamation-triangle"></i> <strong>Template Format:</strong><br>
                            <small>Course Code, Course Name, Description, Program of Study, Year of Study, Semester, Total Weeks, Lecturer ID (optional), Associated Programs (optional, semicolon-separated)</small>
                        </div>
                        
                        <div class="alert alert-secondary py-2">
                            <small><i class="bi bi-arrow-repeat"></i> <strong>Update behaviour:</strong> If a course code already exists, its details and associated programs will be <strong>updated</strong> from the CSV. Leave the Associated Programs column empty to keep existing associations unchanged.</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-info">
                            <i class="bi bi-upload"></i> Upload & Import
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Add Course Modal -->
    <div class="modal fade" id="addCourseModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title"><i class="bi bi-plus-circle"></i> Add New Course</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="">
                    <div class="modal-body">
                        <input type="hidden" name="create_course" value="1">
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold"><i class="bi bi-code"></i> Course Code *</label>
                                <div class="input-group">
                                    <input type="text" class="form-control" name="course_code" id="addCourseCode" required placeholder="e.g., ICT 110, LSM 425">
                                    <button type="button" class="btn btn-primary" onclick="autoAssignFromCode()" title="Auto-fill Department, Year & Semester from course code">
                                        <i class="bi bi-magic me-1"></i>Auto-Assign
                                    </button>
                                </div>
                                <div id="autoAssignResult" class="mt-1" style="display:none;"></div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold"><i class="bi bi-calendar-week"></i> Total Weeks *</label>
                                <input type="number" class="form-control" name="total_weeks" value="16" required min="1">
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold"><i class="bi bi-book"></i> Course Name *</label>
                            <input type="text" class="form-control" name="course_name" required placeholder="e.g., Introduction to Programming">
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold"><i class="bi bi-text-paragraph"></i> Description</label>
                            <textarea class="form-control" name="description" rows="3" placeholder="Course description..."></textarea>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold"><i class="bi bi-diagram-3"></i> Program of Study *</label>
                                <select class="form-select" name="program" id="addCourseProgram" required>
                                    <option value="">Select program...</option>
                                    <?php foreach ($programs as $program): ?>
                                        <option value="<?php echo htmlspecialchars($program); ?>"><?php echo htmlspecialchars($program); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold"><i class="bi bi-123"></i> Year(s) of Study *</label>
                                <div class="border rounded p-2">
                                    <div class="form-check form-check-inline">
                                        <input class="form-check-input add-year-check" type="checkbox" name="years[]" value="1" id="addYear1">
                                        <label class="form-check-label fw-semibold" for="addYear1">Year 1</label>
                                    </div>
                                    <div class="form-check form-check-inline">
                                        <input class="form-check-input add-year-check" type="checkbox" name="years[]" value="2" id="addYear2">
                                        <label class="form-check-label fw-semibold" for="addYear2">Year 2</label>
                                    </div>
                                    <div class="form-check form-check-inline">
                                        <input class="form-check-input add-year-check" type="checkbox" name="years[]" value="3" id="addYear3">
                                        <label class="form-check-label fw-semibold" for="addYear3">Year 3</label>
                                    </div>
                                    <div class="form-check form-check-inline">
                                        <input class="form-check-input add-year-check" type="checkbox" name="years[]" value="4" id="addYear4">
                                        <label class="form-check-label fw-semibold" for="addYear4">Year 4</label>
                                    </div>
                                </div>
                                <small class="text-muted">Select all years that can take this course</small>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold"><i class="bi bi-calendar"></i> Semester *</label>
                                <select class="form-select" name="semester" id="addCourseSemester" required>
                                    <option value="">Select semester...</option>
                                    <option value="One">Semester 1</option>
                                    <option value="Two">Semester 2</option>
                                    <option value="Both">Both Semesters</option>
                                </select>
                                <small class="text-muted">Select "Both" if offered in Semester 1 &amp; 2</small>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold"><i class="bi bi-person"></i> Assign Lecturer (Optional - Can teach any course)</label>
                            <select class="form-select" name="lecturer_id">
                                <option value="">No lecturer assigned</option>
                                <?php foreach ($lecturers as $lecturer): ?>
                                    <option value="<?php echo $lecturer['lecturer_id']; ?>"><?php echo htmlspecialchars($lecturer['full_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">Lecturers can be assigned to courses from any department or program</small>
                        </div>
                        
                        <!-- Associated Programs -->
                        <div class="mb-3">
                            <label class="form-label fw-bold"><i class="bi bi-link-45deg"></i> Associate with Additional Programs</label>
                            <div class="alert alert-info py-2 mb-2">
                                <small><i class="bi bi-info-circle"></i> Select additional programs where this course should also be available (cross-departmental courses).</small>
                            </div>
                            <div class="border rounded p-3" style="max-height: 200px; overflow-y: auto;">
                                <?php if (!empty($all_programs)): ?>
                                    <?php foreach ($all_programs as $prog): ?>
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" 
                                                   name="additional_programs[]" 
                                                   value="<?php echo $prog['program_id']; ?>"
                                                   id="add_prog_<?php echo $prog['program_id']; ?>">
                                            <label class="form-check-label" for="add_prog_<?php echo $prog['program_id']; ?>">
                                                <?php echo htmlspecialchars($prog['program_name']); ?> (<?php echo htmlspecialchars($prog['program_code']); ?>)
                                            </label>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <p class="text-muted mb-0">No programs available.</p>
                                <?php endif; ?>
                            </div>
                            <small class="text-muted">Students from selected programs will also see this course in their module selection.</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-warning"><i class="bi bi-save"></i> Create Course</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Enroll by Program Modal -->
    <div class="modal fade" id="enrollByProgramModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title"><i class="bi bi-people-fill"></i> Enroll Students by Program</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="">
                    <div class="modal-body">
                        <input type="hidden" name="enroll_by_program" value="1">
                        <input type="hidden" name="course_id" id="programModalCourseId">
                        
                        <div class="alert alert-info">
                            <strong>Course:</strong> <span id="programCourseName"></span>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold"><i class="bi bi-diagram-3"></i> Filter by Program</label>
                            <select class="form-select" name="program_filter">
                                <option value="">All Programs</option>
                                <?php foreach ($programs as $program): ?>
                                    <option value="<?php echo htmlspecialchars($program); ?>"><?php echo htmlspecialchars($program); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold"><i class="bi bi-123"></i> Filter by Year of Study</label>
                            <select class="form-select" name="year_filter">
                                <option value="">All Years</option>
                                <option value="1">Year 1</option>
                                <option value="2">Year 2</option>
                                <option value="3">Year 3</option>
                                <option value="4">Year 4</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold"><i class="bi bi-calendar-check"></i> Filter by Semester</label>
                            <select class="form-select" name="semester_filter">
                                <option value="">All Semesters</option>
                                <option value="One">Semester One</option>
                                <option value="Two">Semester Two</option>
                            </select>
                        </div>
                        
                        <div class="alert alert-warning">
                            <i class="bi bi-info-circle"></i> All students matching the selected filters will be enrolled in this course.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success"><i class="bi bi-people-fill"></i> Enroll Students</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Individual Student Enrollment Modal -->
    <div class="modal fade" id="enrollModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="bi bi-person-plus"></i> Enroll Individual Student</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="">
                    <div class="modal-body">
                        <input type="hidden" name="enroll_student" value="1">
                        <input type="hidden" name="course_id" id="modalCourseId">
                        
                        <div class="alert alert-info">
                            <strong>Course:</strong> <span id="courseName"></span>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold"><i class="bi bi-person"></i> Select Student *</label>
                            <select class="form-select" name="student_id" id="student_id" required>
                                <option value="">Choose a student...</option>
                                <?php foreach ($students as $student): ?>
                                    <option value="<?php echo $student['student_id']; ?>">
                                        <?php echo htmlspecialchars($student['full_name'] . ' (' . $student['student_id'] . ') - ' . ($student['program'] ?? 'No Program') . ' - Year ' . ($student['year_of_study'] ?? 'N/A')); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary"><i class="bi bi-person-plus"></i> Enroll Student</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- View Enrolled Students Modal -->
    <div class="modal fade" id="viewStudentsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title"><i class="bi bi-eye"></i> Enrolled Students in <span id="viewCourseName"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="enrolledStudentsList">
                        <div class="text-center py-4">
                            <div class="spinner-border text-primary" role="status">
                                <span class="visually-hidden">Loading...</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Delete Course Confirmation Modal -->
    <div class="modal fade" id="deleteCourseModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="bi bi-trash"></i> Delete Course</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="delete_course" value="1">
                        <input type="hidden" name="course_id" id="deleteCourseId">
                        
                        <div class="text-center mb-3">
                            <i class="bi bi-exclamation-triangle text-danger" style="font-size: 4rem;"></i>
                        </div>
                        
                        <p class="text-center fs-5">Are you sure you want to delete the course:</p>
                        <p class="text-center fw-bold fs-4 text-danger" id="deleteCourseName"></p>
                        
                        <div class="alert alert-warning d-none" id="deleteWarning">
                            <i class="bi bi-people-fill"></i> <strong>Warning:</strong> This course has <span id="deleteEnrolledCount" class="fw-bold"></span> enrolled student(s). 
                            All enrollments will be removed.
                        </div>
                        
                        <div class="alert alert-danger">
                            <i class="bi bi-exclamation-circle"></i> <strong>This action cannot be undone!</strong> 
                            All course content, materials, and enrollment records will be permanently deleted.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="bi bi-x-circle"></i> Cancel
                        </button>
                        <button type="submit" class="btn btn-danger">
                            <i class="bi bi-trash"></i> Delete Course
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Allocate Students Modal -->
    <div class="modal fade" id="allocateStudentsModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title">
                        <i class="bi bi-person-plus-fill"></i> Allocate Students to Course: <span id="allocateCourseTitle"></span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="allocateStudentsForm">
                    <div class="modal-body">
                        <input type="hidden" name="bulk_allocate_students" value="1">
                        <input type="hidden" name="allocate_course_id" id="allocateCourseId">
                        
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle"></i> Select students to give them access to course content uploaded by the lecturer
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-4">
                                <label class="form-label">Filter by Program</label>
                                <select class="form-select" id="filterProgram">
                                    <option value="">All Programs</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Filter by Year</label>
                                <select class="form-select" id="filterYear">
                                    <option value="">All Years</option>
                                    <option value="1">Year 1</option>
                                    <option value="2">Year 2</option>
                                    <option value="3">Year 3</option>
                                    <option value="4">Year 4</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Search</label>
                                <input type="text" class="form-control" id="searchStudent" placeholder="Search by ID or name...">
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <button type="button" class="btn btn-sm btn-outline-primary" onclick="selectAllStudents()">
                                <i class="bi bi-check-all"></i> Select All
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="deselectAllStudents()">
                                <i class="bi bi-x-circle"></i> Deselect All
                            </button>
                            <span class="ms-3 badge bg-primary" id="selectedCount">0 selected</span>
                        </div>
                        
                        <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                            <table class="table table-hover table-sm">
                                <thead class="table-dark sticky-top">
                                    <tr>
                                        <th width="40">
                                            <input type="checkbox" class="form-check-input" id="selectAllCheckbox" onchange="toggleAllStudents(this)">
                                        </th>
                                        <th>Student ID</th>
                                        <th>Full Name</th>
                                        <th>Program</th>
                                        <th>Year</th>
                                        <th>Semester</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody id="studentsTableBody">
                                    <tr>
                                        <td colspan="7" class="text-center text-muted py-4">
                                            <div class="spinner-border spinner-border-sm" role="status">
                                                <span class="visually-hidden">Loading...</span>
                                            </div> Loading students...
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-warning">
                            <i class="bi bi-check-circle"></i> Allocate Selected Students
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Table sorting functionality
        let sortDirection = {};
        
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('.sortable').forEach(function(header) {
                header.addEventListener('click', function() {
                    const sortKey = this.dataset.sort;
                    sortTable(sortKey);
                });
            });
        });
        
        function sortTable(sortKey) {
            const table = document.getElementById('coursesTable');
            const tbody = document.getElementById('coursesTableBody');
            const rows = Array.from(tbody.querySelectorAll('tr'));
            
            // Toggle sort direction
            sortDirection[sortKey] = !sortDirection[sortKey];
            const ascending = sortDirection[sortKey];
            
            rows.sort(function(a, b) {
                let valA, valB;
                
                switch(sortKey) {
                    case 'code':
                        valA = a.dataset.code || '';
                        valB = b.dataset.code || '';
                        break;
                    case 'name':
                        valA = a.dataset.name || '';
                        valB = b.dataset.name || '';
                        break;
                    case 'program':
                        valA = a.dataset.program || '';
                        valB = b.dataset.program || '';
                        break;
                    default:
                        valA = '';
                        valB = '';
                }
                
                valA = valA.toLowerCase();
                valB = valB.toLowerCase();
                
                if (ascending) {
                    return valA.localeCompare(valB);
                } else {
                    return valB.localeCompare(valA);
                }
            });
            
            // Re-append sorted rows
            rows.forEach(function(row) {
                tbody.appendChild(row);
            });
            
            // Update sort icons
            document.querySelectorAll('.sortable i').forEach(function(icon) {
                icon.className = 'bi bi-arrow-down-up ms-1';
            });
            
            const activeHeader = document.querySelector('.sortable[data-sort="' + sortKey + '"] i');
            if (activeHeader) {
                activeHeader.className = ascending ? 'bi bi-sort-alpha-down ms-1' : 'bi bi-sort-alpha-up ms-1';
            }
        }
        
        let allStudents = [];
        let currentCourseId = null;
        let currentCourseProgram = '';
        let currentCourseYear = '';
        
        function openAllocateStudentsModal(courseId, courseCode, courseName, program, year) {
            currentCourseId = courseId;
            currentCourseProgram = program;
            currentCourseYear = year || '';
            
            document.getElementById('allocateCourseTitle').textContent = courseCode + ' - ' + courseName;
            document.getElementById('allocateCourseId').value = courseId;
            
            const modal = new bootstrap.Modal(document.getElementById('allocateStudentsModal'));
            modal.show();
            
            // Load students
            loadStudentsForAllocation(program, year || '');
        }
        
        function loadStudentsForAllocation(suggestedProgram, suggestedYear) {
            fetch('get_students_for_allocation.php?course_id=' + currentCourseId)
                .then(response => response.json())
                .then(data => {
                    allStudents = data.students || [];
                    
                    // Populate program filter
                    const programFilter = document.getElementById('filterProgram');
                    const programs = [...new Set(allStudents.map(s => s.program))];
                    programFilter.innerHTML = '<option value="">All Programs</option>';
                    programs.forEach(prog => {
                        const option = document.createElement('option');
                        option.value = prog;
                        option.textContent = prog;
                        if (prog === suggestedProgram) option.selected = true;
                        programFilter.appendChild(option);
                    });
                    
                    // Set suggested year only if valid
                    if (suggestedYear && suggestedYear !== '' && suggestedYear > 0) {
                        document.getElementById('filterYear').value = suggestedYear;
                    } else {
                        document.getElementById('filterYear').value = '';
                    }
                    
                    displayStudents();
                })
                .catch(error => {
                    document.getElementById('studentsTableBody').innerHTML = 
                        '<tr><td colspan="7" class="text-center text-danger">Error loading students</td></tr>';
                });
        }
        
        function displayStudents() {
            const filterProgram = document.getElementById('filterProgram').value;
            const filterYear = document.getElementById('filterYear').value;
            const searchTerm = document.getElementById('searchStudent').value.toLowerCase();
            
            const filtered = allStudents.filter(student => {
                const matchProgram = !filterProgram || student.program === filterProgram;
                const matchYear = !filterYear || student.year_of_study == filterYear;
                const matchSearch = !searchTerm || 
                    student.student_id.toLowerCase().includes(searchTerm) ||
                    student.full_name.toLowerCase().includes(searchTerm);
                
                return matchProgram && matchYear && matchSearch;
            });
            
            const tbody = document.getElementById('studentsTableBody');
            tbody.innerHTML = '';
            
            if (filtered.length === 0) {
                tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted">No students found</td></tr>';
                return;
            }
            
            filtered.forEach(student => {
                const isEnrolled = student.is_enrolled;
                const matchesCourse = student.program === currentCourseProgram && student.year_of_study == currentCourseYear;
                
                const row = document.createElement('tr');
                if (matchesCourse) row.classList.add('table-success', 'table-success-subtle');
                if (isEnrolled) row.classList.add('table-warning');
                
                row.innerHTML = `
                    <td>
                        <input type="checkbox" class="form-check-input student-checkbox" 
                               name="student_ids[]" value="${student.student_id}"
                               ${isEnrolled ? 'disabled' : ''}
                               onchange="updateSelectedCount()">
                    </td>
                    <td>${student.student_id}</td>
                    <td>${student.full_name}</td>
                    <td><span class="badge ${matchesCourse ? 'bg-success' : 'bg-secondary'}">${student.program}</span></td>
                    <td>Year ${student.year_of_study}</td>
                    <td>Sem ${student.semester}</td>
                    <td>
                        ${isEnrolled ? '<span class="badge bg-warning text-dark">Already Enrolled</span>' : 
                          matchesCourse ? '<span class="badge bg-success">Matching</span>' : 
                          '<span class="badge bg-light text-dark">Available</span>'}
                    </td>
                `;
                
                tbody.appendChild(row);
            });
            
            updateSelectedCount();
        }
        
        function toggleAllStudents(checkbox) {
            const checkboxes = document.querySelectorAll('.student-checkbox:not(:disabled)');
            checkboxes.forEach(cb => cb.checked = checkbox.checked);
            updateSelectedCount();
        }
        
        function selectAllStudents() {
            const checkboxes = document.querySelectorAll('.student-checkbox:not(:disabled)');
            checkboxes.forEach(cb => cb.checked = true);
            document.getElementById('selectAllCheckbox').checked = true;
            updateSelectedCount();
        }
        
        function deselectAllStudents() {
            const checkboxes = document.querySelectorAll('.student-checkbox');
            checkboxes.forEach(cb => cb.checked = false);
            document.getElementById('selectAllCheckbox').checked = false;
            updateSelectedCount();
        }
        
        function updateSelectedCount() {
            const count = document.querySelectorAll('.student-checkbox:checked').length;
            document.getElementById('selectedCount').textContent = count + ' selected';
        }
        
        // Event listeners for filters
        document.getElementById('filterProgram')?.addEventListener('change', displayStudents);
        document.getElementById('filterYear')?.addEventListener('change', displayStudents);
        document.getElementById('searchStudent')?.addEventListener('input', displayStudents);
        
        // Course filtering functionality
        function applyFilters() {
            const program = document.getElementById('filterProgram').value;
            const year = document.getElementById('filterYear').value;
            const semester = document.getElementById('filterSemester').value;
            const searchTerm = (document.getElementById('filterSearch').value || '').toLowerCase();
            const rows = document.querySelectorAll('#coursesTableBody tr');
            let visibleCount = 0;

            rows.forEach(row => {
                const rowProgram = (row.dataset.program || '').trim();
                const rowYear = (row.dataset.year || '0');
                const rowSem = (row.dataset.semester || '').trim();
                const rowCode = (row.dataset.code || '').toLowerCase();
                const rowName = (row.dataset.name || '').toLowerCase();

                const matchesProgram = !program || rowProgram.toLowerCase() === program.toLowerCase();
                const matchesYear = !year || rowYear === year;
                const matchesSem = !semester || rowSem === semester;
                const matchesSearch = !searchTerm || rowCode.includes(searchTerm) || rowName.includes(searchTerm);

                if (matchesProgram && matchesYear && matchesSem && matchesSearch) {
                    row.style.display = '';
                    visibleCount++;
                } else {
                    row.style.display = 'none';
                }
            });

            // Update active filter display bar
            const display = document.getElementById('activeFilterDisplay');
            const textEl = document.getElementById('activeFilterText');
            const countEl = document.getElementById('activeFilterCount');

            if (program || year || semester || searchTerm) {
                display.classList.add('show');
                let parts = [];
                if (program) parts.push(program);
                if (year) parts.push('Year ' + year);
                if (semester) parts.push('Semester ' + semester);
                if (searchTerm) parts.push('"' + searchTerm + '"');
                textEl.textContent = parts.join(' — ');
                countEl.textContent = visibleCount + ' course' + (visibleCount !== 1 ? 's' : '');
            } else {
                display.classList.remove('show');
            }
        }

        function clearAllFilters() {
            document.getElementById('filterProgram').value = '';
            document.getElementById('filterYear').value = '';
            document.getElementById('filterSemester').value = '';
            document.getElementById('filterSearch').value = '';
            document.getElementById('activeFilterDisplay').classList.remove('show');
            document.querySelectorAll('#coursesTableBody tr').forEach(row => row.style.display = '');
            window.history.pushState({}, '', 'manage_courses.php');
        }

        // Legacy aliases for compatibility
        function filterCourses() { applyFilters(); }
        function clearCourseFilters() { clearAllFilters(); }
        
        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            // Check for URL parameter
            const urlParams = new URLSearchParams(window.location.search);
            const programParam = urlParams.get('program');
            
            if (programParam) {
                // Set the filter dropdown and apply
                const progSelect = document.getElementById('filterProgram');
                for (const opt of progSelect.options) {
                    if (opt.value.toLowerCase() === programParam.toLowerCase()) {
                        progSelect.value = opt.value;
                        break;
                    }
                }
                applyFilters();
                
                // Show notification
                const alert = document.createElement('div');
                alert.className = 'alert alert-info alert-dismissible fade show';
                alert.innerHTML = `
                    <i class="bi bi-filter-circle"></i> Showing courses for program: <strong>${programParam}</strong>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                `;
                const container = document.querySelector('.container-fluid');
                container.insertBefore(alert, container.firstChild.nextSibling.nextSibling);
            }
        });
        
        function openEnrollByProgramModal(courseId, courseName) {
            document.getElementById('programModalCourseId').value = courseId;
            document.getElementById('programCourseName').textContent = courseName;
            new bootstrap.Modal(document.getElementById('enrollByProgramModal')).show();
        }
        
        function openEnrollModal(courseId, courseName) {
            document.getElementById('modalCourseId').value = courseId;
            document.getElementById('courseName').textContent = courseName;
            new bootstrap.Modal(document.getElementById('enrollModal')).show();
        }
        
        function viewEnrolledStudents(courseId, courseName) {
            document.getElementById('viewCourseName').textContent = courseName;
            const modal = new bootstrap.Modal(document.getElementById('viewStudentsModal'));
            modal.show();
            
            // Fetch enrolled students via AJAX
            fetch('get_enrolled_students.php?course_id=' + courseId)
                .then(response => response.json())
                .then(data => {
                    let html = '';
                    if (data.students && data.students.length > 0) {
                        html = '<div class="table-responsive"><table class="table table-striped"><thead class="table-dark"><tr><th>Student ID</th><th>Name</th><th>Program</th><th>Year</th><th>Enrolled Date</th><th>Action</th></tr></thead><tbody>';
                        data.students.forEach(student => {
                            html += `<tr>
                                <td>${student.student_id}</td>
                                <td>${student.full_name}</td>
                                <td>${student.program || 'N/A'}</td>
                                <td>${student.year_of_study ? 'Year ' + student.year_of_study : 'N/A'}</td>
                                <td>${student.enrollment_date}</td>
                                <td>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to unenroll this student?');">
                                        <input type="hidden" name="unenroll_student" value="1">
                                        <input type="hidden" name="enrollment_id" value="${student.enrollment_id}">
                                        <button type="submit" class="btn btn-sm btn-danger"><i class="bi bi-x-circle"></i> Unenroll</button>
                                    </form>
                                </td>
                            </tr>`;
                        });
                        html += '</tbody></table></div>';
                    } else {
                        html = '<div class="alert alert-warning"><i class="bi bi-exclamation-circle"></i> No students enrolled in this course yet.</div>';
                    }
                    document.getElementById('enrolledStudentsList').innerHTML = html;
                })
                .catch(error => {
                    document.getElementById('enrolledStudentsList').innerHTML = '<div class="alert alert-danger">Error loading students</div>';
                });
        }
        
        function confirmDeleteCourse(courseId, courseName, enrolledCount) {
            document.getElementById('deleteCourseId').value = courseId;
            document.getElementById('deleteCourseName').textContent = courseName;
            document.getElementById('deleteEnrolledCount').textContent = enrolledCount;
            
            // Show warning if students are enrolled
            const warningDiv = document.getElementById('deleteWarning');
            if (enrolledCount > 0) {
                warningDiv.classList.remove('d-none');
            } else {
                warningDiv.classList.add('d-none');
            }
            
            new bootstrap.Modal(document.getElementById('deleteCourseModal')).show();
        }
        
        // ==========================================
        // Auto-Assign from Course Code
        // ==========================================
        
        // Course code prefix → program/department mapping
        const codeToProgramMap = {
            // Information Technology
            'ICT': 'Information Technology',
            'BIT': 'Information Technology',
            'IT': 'Information Technology',
            'CS': 'Computer Science',
            'CSC': 'Computer Science',
            'CIT': 'Information Technology',
            
            // Logistics & Supply Chain Management
            'LSM': 'Logistics and Supply Chain Management',
            'LOG': 'Logistics and Supply Chain Management',
            'SCM': 'Logistics and Supply Chain Management',
            'LSCM': 'Logistics and Supply Chain Management',
            
            // Community Development
            'COD': 'Community Development',
            'CD': 'Community Development',
            'BACD': 'Community Development',
            
            // Human Resource Management
            'BAHRM': 'Human Resource Management',
            'HRM': 'Human Resource Management',
            'HR': 'Human Resource Management',
            
            // Business Administration
            'BUS': 'Business Administration',
            'BA': 'Business Administration',
            'BBA': 'Business Administration',
            'BAM': 'Business Administration',
            'MGT': 'Business Administration',
            
            // Accounting & Finance
            'ACC': 'Accounting and Finance',
            'BAAF': 'Accounting and Finance',
            'FIN': 'Accounting and Finance',
            'ACF': 'Accounting and Finance',
            
            // Economics
            'ECO': 'Economics',
            'ECON': 'Economics',
            'EC': 'Economics',
            
            // Education
            'EDU': 'Education',
            'BED': 'Education',
            'TCH': 'Education',
            
            // Law
            'LAW': 'Law',
            'LLB': 'Law',
            
            // Nursing / Health Sciences
            'NUR': 'Nursing',
            'HSC': 'Health Sciences',
            'HSM': 'Health Systems Management',
            'PH': 'Public Health',
            'PHC': 'Public Health',
            
            // Marketing
            'MKT': 'Marketing',
            'BAMK': 'Marketing',
            
            // Public Administration
            'PAD': 'Public Administration',
            'PA': 'Public Administration',
            
            // Journalism / Mass Communication
            'JMC': 'Journalism and Mass Communication',
            'MC': 'Mass Communication',
            'COM': 'Communication Studies',
            
            // Agriculture
            'AGR': 'Agriculture',
            'AG': 'Agriculture',
            
            // Social Work
            'SW': 'Social Work',
            'SOC': 'Sociology',
            
            // Mathematics & Statistics
            'MAT': 'Mathematics',
            'MATH': 'Mathematics',
            'STA': 'Statistics',
            'STAT': 'Statistics',
            
            // Engineering
            'ENG': 'Engineering',
            'CE': 'Civil Engineering',
            'EE': 'Electrical Engineering',
            'ME': 'Mechanical Engineering',
        };
        
        function autoAssignFromCode() {
            const codeInput = document.getElementById('addCourseCode');
            const code = codeInput.value.trim().toUpperCase();
            const resultDiv = document.getElementById('autoAssignResult');
            
            if (!code) {
                resultDiv.style.display = 'block';
                resultDiv.innerHTML = '<small class="text-danger"><i class="bi bi-exclamation-circle me-1"></i>Please enter a course code first.</small>';
                return;
            }
            
            // Parse: split letters from numbers
            // E.g. "LSM 425" → prefix="LSM", numbers="425"
            // E.g. "BAHRM 1202" → prefix="BAHRM", numbers="1202"
            // E.g. "BIT1102" → prefix="BIT", numbers="1102"
            const cleaned = code.replace(/\s+/g, '');
            const match = cleaned.match(/^([A-Z]+)(\d+)$/);
            
            if (!match) {
                resultDiv.style.display = 'block';
                resultDiv.innerHTML = '<small class="text-danger"><i class="bi bi-exclamation-circle me-1"></i>Invalid format. Use letters + numbers (e.g. ICT 110, LSM 425).</small>';
                return;
            }
            
            const prefix = match[1];
            const numPart = match[2];
            
            // First digit = year, second digit = semester
            const year = parseInt(numPart.charAt(0));
            const semDigit = parseInt(numPart.charAt(1));
            const semester = (semDigit === 2) ? 'Two' : 'One';
            
            // Look up program from prefix (try longest match first)
            let program = null;
            let matchedPrefix = null;
            
            // Sort prefixes by length descending so BAHRM matches before BA
            const sortedPrefixes = Object.keys(codeToProgramMap).sort((a, b) => b.length - a.length);
            for (const p of sortedPrefixes) {
                if (prefix === p) {
                    program = codeToProgramMap[p];
                    matchedPrefix = p;
                    break;
                }
            }
            
            // If no exact match, try starts-with longest
            if (!program) {
                for (const p of sortedPrefixes) {
                    if (prefix.startsWith(p) || p.startsWith(prefix)) {
                        program = codeToProgramMap[p];
                        matchedPrefix = p;
                        break;
                    }
                }
            }
            
            // Set Year (checkbox instead of select)
            if (year >= 1 && year <= 4) {
                // Uncheck all first, then check the detected year
                document.querySelectorAll('.add-year-check').forEach(cb => cb.checked = false);
                const yearCb = document.getElementById('addYear' + year);
                if (yearCb) yearCb.checked = true;
            }
            
            // Set Semester
            document.getElementById('addCourseSemester').value = semester;
            
            // Set Program
            let programSet = false;
            if (program) {
                const progSelect = document.getElementById('addCourseProgram');
                const options = Array.from(progSelect.options);
                
                // Try exact match first
                for (const opt of options) {
                    if (opt.value.toLowerCase() === program.toLowerCase()) {
                        progSelect.value = opt.value;
                        programSet = true;
                        break;
                    }
                }
                
                // Try partial/contains match
                if (!programSet) {
                    const keywords = program.toLowerCase().split(/\s+/);
                    for (const opt of options) {
                        if (!opt.value) continue;
                        const optLower = opt.value.toLowerCase();
                        const matchCount = keywords.filter(k => k.length > 2 && optLower.includes(k)).length;
                        if (matchCount >= Math.ceil(keywords.length * 0.5)) {
                            progSelect.value = opt.value;
                            programSet = true;
                            break;
                        }
                    }
                }
            }
            
            // Show result
            resultDiv.style.display = 'block';
            let html = '<div class="d-flex flex-wrap gap-2 align-items-center">';
            
            if (program) {
                html += `<span class="badge ${programSet ? 'bg-success' : 'bg-warning text-dark'}" style="font-size:0.78rem;"><i class="bi bi-building me-1"></i>${program}${!programSet ? ' (not in list)' : ''}</span>`;
            } else {
                html += '<span class="badge bg-danger" style="font-size:0.78rem;"><i class="bi bi-question-circle me-1"></i>Unknown prefix: ' + prefix + '</span>';
            }
            
            html += `<span class="badge bg-primary" style="font-size:0.78rem;"><i class="bi bi-123 me-1"></i>Year ${year}</span>`;
            html += `<span class="badge bg-info" style="font-size:0.78rem;"><i class="bi bi-calendar me-1"></i>Semester ${semester === 'One' ? '1' : '2'}</span>`;
            html += '</div>';
            
            if (program && !programSet) {
                html += '<small class="text-warning d-block mt-1"><i class="bi bi-exclamation-triangle me-1"></i>Program "' + program + '" not found in the dropdown. Please add it to Programs first or select manually.</small>';
            }
            
            resultDiv.innerHTML = html;
            
            // Flash the changed fields
            ['addCourseProgram', 'addCourseSemester'].forEach(id => {
                const el = document.getElementById(id);
                el.style.transition = 'box-shadow 0.3s, border-color 0.3s';
                el.style.borderColor = '#198754';
                el.style.boxShadow = '0 0 0 3px rgba(25,135,84,0.25)';
                setTimeout(() => {
                    el.style.borderColor = '';
                    el.style.boxShadow = '';
                }, 2000);
            });
        }
        
        // Auto-assign on Enter key in course code field
        document.getElementById('addCourseCode')?.addEventListener('keyup', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                autoAssignFromCode();
            }
        });
        
        // Validate Add Course form: require at least one year checkbox
        document.querySelector('#addCourseModal form')?.addEventListener('submit', function(e) {
            const checkedYears = document.querySelectorAll('.add-year-check:checked');
            if (checkedYears.length === 0) {
                e.preventDefault();
                alert('Please select at least one Year of Study.');
            }
        });

        // ==========================================
        // Inline Update Functions
        // ==========================================
        function inlineUpdateProgram(select) {
            const courseId = select.dataset.courseId;
            const value = select.value;
            const row = select.closest('tr');
            
            select.disabled = true;
            select.style.opacity = '0.6';
            
            const formData = new FormData();
            formData.append('inline_update_course', '1');
            formData.append('course_id', courseId);
            formData.append('field', 'program');
            formData.append('value', value);
            
            fetch('manage_courses.php', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    select.disabled = false;
                    select.style.opacity = '1';
                    if (data.success) {
                        // Flash green
                        select.style.borderColor = '#198754';
                        select.style.boxShadow = '0 0 0 3px rgba(25,135,84,0.25)';
                        setTimeout(() => { select.style.borderColor = ''; select.style.boxShadow = ''; }, 1500);
                        // Update data attribute for filtering
                        row.dataset.program = value || '';
                    } else {
                        select.style.borderColor = '#dc3545';
                        alert('Update failed: ' + data.message);
                        setTimeout(() => { select.style.borderColor = ''; }, 1500);
                    }
                })
                .catch(() => {
                    select.disabled = false;
                    select.style.opacity = '1';
                    select.style.borderColor = '#dc3545';
                    alert('Network error. Please try again.');
                });
        }
        
        function inlineUpdateYear(select) {
            const courseId = select.dataset.courseId;
            const value = select.value;
            const row = select.closest('tr');
            
            let year = 0, semester = 'One';
            if (value) {
                const parts = value.split('-');
                year = parseInt(parts[0]);
                semester = parts[1];
            }
            
            select.disabled = true;
            select.style.opacity = '0.6';
            
            const formData = new FormData();
            formData.append('inline_update_course', '1');
            formData.append('course_id', courseId);
            formData.append('field', 'year_semester');
            formData.append('year', year);
            formData.append('semester', semester);
            
            fetch('manage_courses.php', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    select.disabled = false;
                    select.style.opacity = '1';
                    if (data.success) {
                        select.style.borderColor = '#198754';
                        select.style.boxShadow = '0 0 0 3px rgba(25,135,84,0.25)';
                        setTimeout(() => { select.style.borderColor = ''; select.style.boxShadow = ''; }, 1500);
                        // Update data attributes for filtering
                        row.dataset.year = year.toString();
                        row.dataset.semester = semester;
                    } else {
                        select.style.borderColor = '#dc3545';
                        alert('Update failed: ' + data.message);
                        setTimeout(() => { select.style.borderColor = ''; }, 1500);
                    }
                })
                .catch(() => {
                    select.disabled = false;
                    select.style.opacity = '1';
                    select.style.borderColor = '#dc3545';
                    alert('Network error. Please try again.');
                });
        }

        // Legacy alias for any remaining references
        function clearQuickFilter() { clearAllFilters(); }
    </script>
</body>
</html>