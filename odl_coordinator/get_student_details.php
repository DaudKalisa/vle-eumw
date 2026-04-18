<?php
/**
 * Get Student Details - AJAX endpoint for ODL Coordinator
 */
require_once '../includes/auth.php';
requireLogin();
requireRole(['odl_coordinator', 'admin', 'staff']);

$conn = getDbConnection();
$student_id = $_GET['id'] ?? '';

if (empty($student_id)) {
    echo '<div class="alert alert-danger">Invalid student ID</div>';
    exit;
}

// Detect program column name in students table
$program_col = 'program';
$col_check = $conn->query("SHOW COLUMNS FROM students LIKE 'program_of_study'");
if ($col_check && $col_check->num_rows > 0) {
    $program_col = 'program_of_study';
}

// Check if programs table has faculty_id/department_id columns
$has_faculty_id = false;
$has_dept_id = false;
$prog_cols = $conn->query("SHOW COLUMNS FROM programs");
if ($prog_cols) {
    while ($col = $prog_cols->fetch_assoc()) {
        if ($col['Field'] === 'faculty_id') $has_faculty_id = true;
        if ($col['Field'] === 'department_id') $has_dept_id = true;
    }
}

// Build dynamic query based on available columns
$select_parts = ["s.*", "p.program_name", "p.program_code", 
    "u.user_id", "u.username", "u.email as user_email", "u.is_active as account_active", "u.created_at as account_created"];
$join_parts = [
    "LEFT JOIN users u ON s.student_id = u.related_student_id",
    "LEFT JOIN programs p ON s.$program_col = p.program_id OR s.$program_col = p.program_code OR s.$program_col = p.program_name"
];

if ($has_faculty_id) {
    $select_parts[] = "f.faculty_name";
    $join_parts[] = "LEFT JOIN faculties f ON p.faculty_id = f.faculty_id";
}
if ($has_dept_id) {
    $select_parts[] = "d.department_name";
    $join_parts[] = "LEFT JOIN departments d ON p.department_id = d.department_id";
}

$sql = "SELECT " . implode(", ", $select_parts) . " FROM students s " . implode(" ", $join_parts) . " WHERE s.student_id = ?";

// Get student details
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $student_id);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();

if (!$student) {
    echo '<div class="alert alert-danger">Student not found</div>';
    exit;
}

// Get login history - check which columns exist
$login_history = [];
$login_table_check = $conn->query("SHOW TABLES LIKE 'login_history'");
if ($login_table_check && $login_table_check->num_rows > 0 && !empty($student['user_id'])) {
    $login_cols = [];
    $cols_result = $conn->query("SHOW COLUMNS FROM login_history");
    while ($col = $cols_result->fetch_assoc()) {
        $login_cols[] = $col['Field'];
    }
    
    // Build select based on available columns
    $select_parts = ['login_time'];
    if (in_array('ip_address', $login_cols)) $select_parts[] = 'ip_address';
    if (in_array('device_type', $login_cols)) $select_parts[] = 'device_type';
    if (in_array('browser', $login_cols)) $select_parts[] = 'browser';
    if (in_array('is_successful', $login_cols)) $select_parts[] = 'is_successful';
    if (in_array('success', $login_cols)) $select_parts[] = 'success as is_successful';
    
    $login_sql = "SELECT " . implode(', ', $select_parts) . " FROM login_history WHERE user_id = ? ORDER BY login_time DESC LIMIT 10";
    $login_stmt = $conn->prepare($login_sql);
    $login_stmt->bind_param("i", $student['user_id']);
    $login_stmt->execute();
    $login_history = $login_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// Get enrolled courses - check for enrolled_at column
$enrolled_courses = [];
$enroll_table = $conn->query("SHOW TABLES LIKE 'vle_enrollments'");
if ($enroll_table && $enroll_table->num_rows > 0) {
    $enroll_cols = [];
    $cols_result = $conn->query("SHOW COLUMNS FROM vle_enrollments");
    while ($col = $cols_result->fetch_assoc()) {
        $enroll_cols[] = $col['Field'];
    }
    
    $has_enrolled_at = in_array('enrolled_at', $enroll_cols);
    $has_created_at = in_array('created_at', $enroll_cols);
    
    $date_col = $has_enrolled_at ? 've.enrolled_at' : ($has_created_at ? 've.created_at' : 'NULL');
    $order_col = $has_enrolled_at ? 've.enrolled_at' : ($has_created_at ? 've.created_at' : 've.enrollment_id');
    
    $course_sql = "
        SELECT vc.course_id, vc.course_code, vc.course_name, $date_col as enrolled_at,
               (SELECT COUNT(*) FROM vle_submissions vs WHERE vs.student_id = ? AND vs.assignment_id IN 
                (SELECT assignment_id FROM vle_assignments WHERE course_id = vc.course_id)) as submissions_count,
               (SELECT COUNT(*) FROM vle_assignments va WHERE va.course_id = vc.course_id) as total_assignments
        FROM vle_enrollments ve
        JOIN vle_courses vc ON ve.course_id = vc.course_id
        WHERE ve.student_id = ?
        ORDER BY $order_col DESC
    ";
    $course_stmt = $conn->prepare($course_sql);
    $course_stmt->bind_param("ss", $student_id, $student_id);
    $course_stmt->execute();
    $enrolled_courses = $course_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// Get finance status
$finance_stmt = $conn->prepare("SELECT * FROM student_finances WHERE student_id = ?");
$finance_stmt->bind_param("s", $student_id);
$finance_stmt->execute();
$finance = $finance_stmt->get_result()->fetch_assoc();
?>

<div class="row">
    <!-- Student Info -->
    <div class="col-md-4">
        <div class="text-center mb-3">
            <?php if (!empty($student['profile_picture']) && file_exists('../uploads/profiles/' . $student['profile_picture'])): ?>
                <img src="../uploads/profiles/<?= htmlspecialchars($student['profile_picture']) ?>" class="rounded-circle" style="width: 100px; height: 100px; object-fit: cover;">
            <?php else: ?>
                <div class="rounded-circle bg-primary d-flex align-items-center justify-content-center mx-auto" style="width: 100px; height: 100px; font-size: 40px; color: white;">
                    <?= strtoupper(substr($student['full_name'], 0, 1)) ?>
                </div>
            <?php endif; ?>
            <h5 class="mt-3 mb-1"><?= htmlspecialchars($student['full_name']) ?></h5>
            <p class="text-muted mb-0"><?= htmlspecialchars($student['student_id']) ?></p>
            
            <!-- Account Status -->
            <div class="mt-2">
                <?php if ($student['account_active']): ?>
                <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Account Active</span>
                <?php else: ?>
                <span class="badge bg-danger"><i class="bi bi-x-circle me-1"></i>Account Inactive</span>
                <?php endif; ?>
            </div>
        </div>
        
        <ul class="list-group list-group-flush small">
            <li class="list-group-item d-flex justify-content-between">
                <span class="text-muted">Email</span>
                <strong><?= htmlspecialchars($student['email']) ?></strong>
            </li>
            <li class="list-group-item d-flex justify-content-between">
                <span class="text-muted">Phone</span>
                <strong><?= htmlspecialchars($student['phone'] ?? 'N/A') ?></strong>
            </li>
            <li class="list-group-item d-flex justify-content-between">
                <span class="text-muted">Program</span>
                <strong><?= htmlspecialchars($student['program_name'] ?? 'N/A') ?></strong>
            </li>
            <li class="list-group-item d-flex justify-content-between">
                <span class="text-muted">Faculty</span>
                <strong><?= htmlspecialchars($student['faculty_name'] ?? 'N/A') ?></strong>
            </li>
            <li class="list-group-item d-flex justify-content-between">
                <span class="text-muted">Year of Study</span>
                <strong><?= $student['year_of_study'] ?? 'N/A' ?></strong>
            </li>
            <li class="list-group-item d-flex justify-content-between">
                <span class="text-muted">Account Created</span>
                <strong><?= $student['account_created'] ? date('M j, Y', strtotime($student['account_created'])) : 'N/A' ?></strong>
            </li>
        </ul>
    </div>
    
    <!-- Course Access & Activity -->
    <div class="col-md-8">
        <!-- Finance Status -->
        <?php if ($finance): ?>
        <div class="alert <?= $finance['payment_percentage'] >= 100 ? 'alert-success' : ($finance['payment_percentage'] >= 50 ? 'alert-warning' : 'alert-danger') ?> mb-3">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <strong>Payment Status:</strong> <?= $finance['payment_percentage'] ?>% paid
                    <div class="small">K<?= number_format($finance['total_paid']) ?> of K<?= number_format($finance['expected_total']) ?></div>
                </div>
                <span class="badge bg-<?= $finance['payment_percentage'] >= 100 ? 'success' : ($finance['payment_percentage'] >= 50 ? 'warning' : 'danger') ?>">
                    <?= $finance['payment_percentage'] >= 100 ? 'Full Access' : ($finance['payment_percentage'] >= 50 ? 'Limited Access' : 'Restricted') ?>
                </span>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Enrolled Courses -->
        <h6><i class="bi bi-book me-2"></i>Enrolled Courses (<?= count($enrolled_courses) ?>)</h6>
        <?php if (!empty($enrolled_courses)): ?>
        <div class="table-responsive mb-4">
            <table class="table table-sm table-bordered">
                <thead class="table-light">
                    <tr>
                        <th>Course</th>
                        <th class="text-center">Submissions</th>
                        <th>Enrolled</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($enrolled_courses as $course): ?>
                    <tr>
                        <td>
                            <strong><?= htmlspecialchars($course['course_code']) ?></strong>
                            <div class="small text-muted"><?= htmlspecialchars($course['course_name']) ?></div>
                        </td>
                        <td class="text-center">
                            <span class="badge bg-<?= $course['submissions_count'] == $course['total_assignments'] ? 'success' : 'warning' ?>">
                                <?= $course['submissions_count'] ?>/<?= $course['total_assignments'] ?>
                            </span>
                        </td>
                        <td><small><?= $course['enrolled_at'] ? date('M j, Y', strtotime($course['enrolled_at'])) : '-' ?></small></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="alert alert-warning mb-4">No course enrollments found</div>
        <?php endif; ?>
        
        <!-- Recent Login Activity -->
        <h6><i class="bi bi-clock-history me-2"></i>Recent Login Activity</h6>
        <?php if (!empty($login_history)): ?>
        <div class="table-responsive">
            <table class="table table-sm table-bordered">
                <thead class="table-light">
                    <tr>
                        <th>Date/Time</th>
                        <th>IP Address</th>
                        <th>Device</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($login_history as $login): ?>
                    <tr>
                        <td><small><?= date('M j, Y g:i a', strtotime($login['login_time'])) ?></small></td>
                        <td><small><?= htmlspecialchars($login['ip_address'] ?? 'N/A') ?></small></td>
                        <td><small><?= htmlspecialchars($login['device_type'] ?? 'N/A') ?></small></td>
                        <td>
                            <?php if ($login['is_successful'] ?? true): ?>
                            <span class="badge bg-success">Success</span>
                            <?php else: ?>
                            <span class="badge bg-danger">Failed</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="alert alert-info">No login history found</div>
        <?php endif; ?>
    </div>
</div>
