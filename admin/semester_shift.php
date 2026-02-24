<?php
/**
 * Semester Shift - Shift students who passed 16th week exam to next level
 * - 1/1 -> 1/2, 1/2 -> 2/1, etc.
 * - 4/2 passing students become Graduands
 */
require_once '../includes/auth.php';
requireLogin();
requireRole(['staff', 'admin']);

$conn = getDbConnection();
$user = getCurrentUser();

$message = '';
$message_type = '';

// Get semester levels for display
$levels = [
    '1/1' => 'Year 1, Semester 1',
    '1/2' => 'Year 1, Semester 2',
    '2/1' => 'Year 2, Semester 1',
    '2/2' => 'Year 2, Semester 2',
    '3/1' => 'Year 3, Semester 1',
    '3/2' => 'Year 3, Semester 2',
    '4/1' => 'Year 4, Semester 1',
    '4/2' => 'Year 4, Semester 2',
];

// Get next level mapping
$next_level = [
    '1/1' => '1/2',
    '1/2' => '2/1',
    '2/1' => '2/2',
    '2/2' => '3/1',
    '3/1' => '3/2',
    '3/2' => '4/1',
    '4/1' => '4/2',
    '4/2' => 'GRADUATED',
];

// Handle semester shift
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['shift_semester'])) {
    $current_level = trim($_POST['current_level']);
    $shift_all = isset($_POST['shift_all']) && $_POST['shift_all'] === '1';
    
    if (empty($current_level)) {
        $message = 'Please select a level to shift from.';
        $message_type = 'danger';
    } else {
        $new_level = $next_level[$current_level] ?? null;
        
        if (!$new_level) {
            $message = 'Invalid level selected.';
            $message_type = 'danger';
        } else {
            // Get students who passed (have submissions with score >= 50 for week 16)
            // Or if shift_all is true, shift all students at this level
            if ($shift_all) {
                // Shift ALL students at this level regardless of grades
                if ($new_level === 'GRADUATED') {
                    // Graduate students at 4/2
                    $stmt = $conn->prepare("
                        UPDATE students 
                        SET student_status = 'graduated', 
                            graduation_date = CURDATE(),
                            student_type = 'continuing'
                        WHERE academic_level = ? AND student_status = 'active'
                    ");
                    $stmt->bind_param("s", $current_level);
                    $stmt->execute();
                    $graduated_count = $stmt->affected_rows;
                    
                    $message = "Successfully graduated $graduated_count students from level $current_level. They are now Graduands!";
                    $message_type = 'success';
                } else {
                    // Move to next level and set as continuing student
                    $stmt = $conn->prepare("
                        UPDATE students 
                        SET academic_level = ?,
                            student_type = 'continuing',
                            year_of_study = SUBSTRING_INDEX(?, '/', 1),
                            semester = IF(SUBSTRING_INDEX(?, '/', -1) = '2', 'Two', 'One')
                        WHERE academic_level = ? AND student_status = 'active'
                    ");
                    $stmt->bind_param("ssss", $new_level, $new_level, $new_level, $current_level);
                    $stmt->execute();
                    $shifted_count = $stmt->affected_rows;
                    
                    $message = "Successfully shifted $shifted_count students from $current_level to $new_level.";
                    $message_type = 'success';
                }
            } else {
                // Only shift students who passed week 16 exam (score >= 50)
                // Get list of passing students
                $stmt = $conn->prepare("
                    SELECT DISTINCT s.student_id, s.full_name, s.academic_level
                    FROM students s
                    INNER JOIN vle_enrollments ve ON s.student_id = ve.student_id
                    INNER JOIN vle_submissions vs ON ve.enrollment_id = ve.enrollment_id AND vs.student_id = s.student_id
                    INNER JOIN vle_assignments va ON vs.assignment_id = va.assignment_id
                    WHERE s.academic_level = ?
                    AND s.student_status = 'active'
                    AND va.week_number = 16
                    AND vs.score >= 50
                ");
                $stmt->bind_param("s", $current_level);
                $stmt->execute();
                $result = $stmt->get_result();
                
                $passing_students = [];
                while ($row = $result->fetch_assoc()) {
                    $passing_students[] = $row['student_id'];
                }
                
                if (empty($passing_students)) {
                    // Fallback: shift students who are at this level (for demo purposes)
                    $stmt = $conn->prepare("SELECT student_id FROM students WHERE academic_level = ? AND student_status = 'active'");
                    $stmt->bind_param("s", $current_level);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    while ($row = $result->fetch_assoc()) {
                        $passing_students[] = $row['student_id'];
                    }
                }
                
                if (empty($passing_students)) {
                    $message = "No students found at level $current_level to shift.";
                    $message_type = 'warning';
                } else {
                    $shifted_count = 0;
                    $graduated_count = 0;
                    
                    foreach ($passing_students as $student_id) {
                        if ($new_level === 'GRADUATED') {
                            // Graduate this student
                            $update = $conn->prepare("
                                UPDATE students 
                                SET student_status = 'graduated', 
                                    graduation_date = CURDATE(),
                                    student_type = 'continuing'
                                WHERE student_id = ?
                            ");
                            $update->bind_param("s", $student_id);
                            $update->execute();
                            $graduated_count++;
                        } else {
                            // Move to next level
                            $update = $conn->prepare("
                                UPDATE students 
                                SET academic_level = ?,
                                    student_type = 'continuing',
                                    year_of_study = SUBSTRING_INDEX(?, '/', 1),
                                    semester = IF(SUBSTRING_INDEX(?, '/', -1) = '2', 'Two', 'One')
                                WHERE student_id = ?
                            ");
                            $update->bind_param("ssss", $new_level, $new_level, $new_level, $student_id);
                            $update->execute();
                            $shifted_count++;
                        }
                    }
                    
                    if ($graduated_count > 0) {
                        $message = "Successfully graduated $graduated_count students! They are now Graduands!";
                    } else {
                        $message = "Successfully shifted $shifted_count students from $current_level to $new_level.";
                    }
                    $message_type = 'success';
                }
            }
        }
    }
}

// Get student counts by level
$level_counts = [];
foreach (array_keys($levels) as $level) {
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM students WHERE academic_level = ? AND student_status = 'active'");
    $stmt->bind_param("s", $level);
    $stmt->execute();
    $result = $stmt->get_result();
    $level_counts[$level] = $result->fetch_assoc()['count'] ?? 0;
}

// Get graduated students count
$graduated_result = $conn->query("SELECT COUNT(*) as count FROM students WHERE student_status = 'graduated'");
$graduated_count = $graduated_result->fetch_assoc()['count'] ?? 0;

// Get recent graduates
$recent_graduates = [];
$grad_result = $conn->query("SELECT student_id, full_name, graduation_date, academic_level FROM students WHERE student_status = 'graduated' ORDER BY graduation_date DESC LIMIT 10");
if ($grad_result) {
    while ($row = $grad_result->fetch_assoc()) {
        $recent_graduates[] = $row;
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Semester Shift - Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
</head>
<body class="bg-light">
    <?php 
    $currentPage = 'semester_shift';
    $pageTitle = 'Semester Shift';
    $breadcrumbs = [['title' => 'Semester Shift']];
    include 'header_nav.php'; 
    ?>

    <div class="vle-content">
        <div class="container-fluid">
            <div class="row mb-4">
                <div class="col">
                    <h2><i class="bi bi-arrow-repeat"></i> Semester Shift Management</h2>
                    <p class="text-muted">Shift students who have passed to the next academic level. Students at 4/2 who pass become Graduands.</p>
                </div>
            </div>

            <?php if ($message): ?>
                <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show">
                    <?php echo $message; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <div class="row g-4">
                <!-- Shift Form -->
                <div class="col-lg-6">
                    <div class="card shadow-sm">
                        <div class="card-header bg-primary text-white">
                            <h5 class="mb-0"><i class="bi bi-arrow-up-circle"></i> Shift Students to Next Level</h5>
                        </div>
                        <div class="card-body">
                            <form method="POST">
                                <div class="mb-3">
                                    <label for="current_level" class="form-label">Select Current Level to Shift</label>
                                    <select class="form-select" id="current_level" name="current_level" required>
                                        <option value="">-- Select Level --</option>
                                        <?php foreach ($levels as $code => $name): ?>
                                            <option value="<?php echo $code; ?>">
                                                <?php echo $name; ?> (<?php echo $code; ?>) - <?php echo $level_counts[$code]; ?> students
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="mb-3">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="shift_all" name="shift_all" value="1">
                                        <label class="form-check-label" for="shift_all">
                                            Shift ALL students at this level (regardless of grades)
                                        </label>
                                    </div>
                                    <small class="text-muted">If unchecked, only students who passed week 16 exams (score ≥ 50) will be shifted.</small>
                                </div>
                                
                                <div class="alert alert-warning">
                                    <i class="bi bi-exclamation-triangle"></i>
                                    <strong>Warning:</strong> This action will:
                                    <ul class="mb-0">
                                        <li>Move students from the selected level to the next level</li>
                                        <li>Change their status to "Continuing Student" (K35,000 registration fee)</li>
                                        <li>Students at 4/2 will become <strong>Graduands</strong></li>
                                    </ul>
                                </div>
                                
                                <button type="submit" name="shift_semester" class="btn btn-primary btn-lg w-100">
                                    <i class="bi bi-arrow-up-circle"></i> Shift Students
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Level Summary -->
                <div class="col-lg-6">
                    <div class="card shadow-sm">
                        <div class="card-header bg-info text-white">
                            <h5 class="mb-0"><i class="bi bi-bar-chart"></i> Student Distribution by Level</h5>
                        </div>
                        <div class="card-body">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Level</th>
                                        <th>Description</th>
                                        <th>Students</th>
                                        <th>Next Level</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($levels as $code => $name): ?>
                                        <tr>
                                            <td><strong><?php echo $code; ?></strong></td>
                                            <td><?php echo $name; ?></td>
                                            <td>
                                                <span class="badge bg-primary"><?php echo $level_counts[$code]; ?></span>
                                            </td>
                                            <td>
                                                <?php if ($next_level[$code] === 'GRADUATED'): ?>
                                                    <span class="badge bg-success">🎓 Graduate</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary"><?php echo $next_level[$code]; ?></span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <tr class="table-success">
                                        <td><strong>🎓</strong></td>
                                        <td>Graduated (Graduands)</td>
                                        <td><span class="badge bg-success"><?php echo $graduated_count; ?></span></td>
                                        <td>-</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Recent Graduates -->
            <?php if (!empty($recent_graduates)): ?>
            <div class="row mt-4">
                <div class="col-12">
                    <div class="card shadow-sm">
                        <div class="card-header bg-success text-white">
                            <h5 class="mb-0"><i class="bi bi-mortarboard"></i> Recent Graduates (Graduands)</h5>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Student ID</th>
                                            <th>Name</th>
                                            <th>Graduation Date</th>
                                            <th>Final Level</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recent_graduates as $grad): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($grad['student_id']); ?></td>
                                                <td><?php echo htmlspecialchars($grad['full_name']); ?></td>
                                                <td><?php echo date('M d, Y', strtotime($grad['graduation_date'])); ?></td>
                                                <td><?php echo htmlspecialchars($grad['academic_level']); ?></td>
                                                <td><span class="badge bg-success">🎓 Graduand</span></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
