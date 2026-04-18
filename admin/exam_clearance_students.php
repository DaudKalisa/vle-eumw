<?php
/**
 * Admin - Manage Exam Clearance Students
 * Admin can view all exam clearance students and convert external students to system students.
 */
require_once '../includes/auth.php';
requireLogin();
requireRole(['admin', 'super_admin', 'staff']);

$conn = getDbConnection();
$user = getCurrentUser();
$success = '';
$error = '';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    // Convert external student to full system student
    if ($_POST['action'] === 'convert_to_student') {
        $clearance_id = (int)($_POST['clearance_id'] ?? 0);
        
        // Load student
        $stmt = $conn->prepare("SELECT * FROM exam_clearance_students WHERE clearance_id = ?");
        $stmt->bind_param("i", $clearance_id);
        $stmt->execute();
        $student = $stmt->get_result()->fetch_assoc();
        
        if (!$student) {
            $error = 'Student not found.';
        } else {
            // Ensure converted_to_student column exists
            $col_check = $conn->query("SHOW COLUMNS FROM exam_clearance_students LIKE 'converted_to_student'");
            if ($col_check && $col_check->num_rows === 0) {
                $conn->query("ALTER TABLE exam_clearance_students ADD COLUMN converted_to_student TINYINT(1) DEFAULT 0 AFTER is_system_student");
                $conn->query("ALTER TABLE exam_clearance_students ADD COLUMN converted_at DATETIME DEFAULT NULL AFTER converted_to_student");
            }
            
            if (!empty($student['converted_to_student'])) {
                $error = 'This student has already been converted to a full system student.';
            } else {
                // Check if student_id already exists in students table
                $check_stmt = $conn->prepare("SELECT student_id FROM students WHERE student_id = ?");
                $check_stmt->bind_param("s", $student['student_id']);
                $check_stmt->execute();
                if ($check_stmt->get_result()->num_rows > 0) {
                    $error = 'A student with ID ' . htmlspecialchars($student['student_id']) . ' already exists in the system.';
                } else {
                    // Insert into students table
                    $ins = $conn->prepare("INSERT INTO students (student_id, full_name, email, phone, program, department, campus, year_of_study, gender, national_id, address, entry_type, semester, year_of_registration, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')");
                    $ins->bind_param("sssssssisssssi",
                        $student['student_id'],
                        $student['full_name'],
                        $student['email'],
                        $student['phone'],
                        $student['program'],
                        $student['department'],
                        $student['campus'],
                        $student['year_of_study'],
                        $student['gender'],
                        $student['national_id'],
                        $student['address'],
                        $student['entry_type'],
                        $student['semester'],
                        $student['year_of_registration']
                    );
                    
                    if ($ins->execute()) {
                        // Update the user account role from exam_clearance_student to student
                        $conn->query("UPDATE users SET role = 'student', related_student_id = '" . $conn->real_escape_string($student['student_id']) . "' WHERE email = '" . $conn->real_escape_string($student['email']) . "' AND role = 'exam_clearance_student'");
                        
                        // Mark as converted
                        $conn->query("UPDATE exam_clearance_students SET converted_to_student = 1, converted_at = NOW(), is_system_student = 1 WHERE clearance_id = $clearance_id");
                        
                        $success = 'Student "' . htmlspecialchars($student['full_name']) . '" successfully converted to full system student! They can now access all student features.';
                    } else {
                        $error = 'Failed to convert student: ' . $conn->error;
                    }
                }
            }
        }
    }
    
    // Delete student record
    if ($_POST['action'] === 'delete' && isset($_POST['clearance_id'])) {
        $cid = (int)$_POST['clearance_id'];
        $conn->query("DELETE FROM exam_clearance_payments WHERE clearance_id = $cid");
        $conn->query("DELETE FROM exam_clearance_students WHERE clearance_id = $cid");
        $success = 'Student record deleted.';
    }
}

// Filters
$filter_status = $_GET['status'] ?? '';
$filter_type = $_GET['type'] ?? ''; // system or external
$search = trim($_GET['search'] ?? '');

$where = "1=1";
$params = [];
$types = '';

if ($filter_status && in_array($filter_status, ['registered', 'invoiced', 'proof_submitted', 'proof_requested', 'cleared', 'rejected'])) {
    $where .= " AND ecs.status = ?";
    $params[] = $filter_status;
    $types .= 's';
}
if ($filter_type === 'system') {
    $where .= " AND ecs.is_system_student = 1";
} elseif ($filter_type === 'external') {
    $where .= " AND ecs.is_system_student = 0";
}
if ($search) {
    $where .= " AND (ecs.student_id LIKE ? OR ecs.full_name LIKE ? OR ecs.email LIKE ?)";
    $s = "%$search%";
    $params[] = $s;
    $params[] = $s;
    $params[] = $s;
    $types .= 'sss';
}

$query = "SELECT ecs.*, 
          (SELECT COALESCE(SUM(ecp.amount), 0) FROM exam_clearance_payments ecp WHERE ecp.clearance_id = ecs.clearance_id AND ecp.status = 'approved') as total_approved
          FROM exam_clearance_students ecs 
          WHERE $where 
          ORDER BY ecs.registered_at DESC";

$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Stats
$stats_rs = $conn->query("SELECT 
    COUNT(*) as total,
    SUM(is_system_student = 1) as system_count,
    SUM(is_system_student = 0) as external_count,
    SUM(IFNULL(converted_to_student, 0) = 1) as converted_count
    FROM exam_clearance_students");
$stats = $stats_rs->fetch_assoc();

$page_title = 'Manage Exam Clearance Students';
$breadcrumbs = [['title' => 'Dashboard', 'url' => 'dashboard.php'], ['title' => 'Exam Clearance Students']];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title ?> - VLE Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
        .stat-card { border-radius: 12px; padding: 1rem 1.25rem; color: #fff; }
        .stat-card h3 { font-size: 1.75rem; font-weight: 700; margin: 0; }
    </style>
</head>
<body>
<?php include 'header_nav.php'; ?>

<div class="container-fluid py-4">
    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle me-2"></i><?= $success ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($error) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>
    
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><i class="bi bi-people me-2 text-primary"></i>Manage Exam Clearance Students</h4>
            <p class="text-muted mb-0">View all exam clearance students and convert external students to system students</p>
        </div>
        <div>
            <a href="exam_clearance_invite_links.php" class="btn btn-outline-success me-2"><i class="bi bi-link-45deg me-1"></i>Invite Links</a>
            <a href="../finance/exam_clearance_students.php" class="btn btn-outline-primary"><i class="bi bi-cash-stack me-1"></i>Finance View</a>
        </div>
    </div>
    
    <!-- Stats Row -->
    <div class="row mb-4">
        <div class="col-md-3 col-sm-6 mb-2">
            <div class="stat-card bg-primary">
                <small class="opacity-75">Total Students</small>
                <h3><?= $stats['total'] ?? 0 ?></h3>
            </div>
        </div>
        <div class="col-md-3 col-sm-6 mb-2">
            <div class="stat-card bg-success">
                <small class="opacity-75">System Students</small>
                <h3><?= $stats['system_count'] ?? 0 ?></h3>
            </div>
        </div>
        <div class="col-md-3 col-sm-6 mb-2">
            <div class="stat-card bg-secondary">
                <small class="opacity-75">External Students</small>
                <h3><?= $stats['external_count'] ?? 0 ?></h3>
            </div>
        </div>
        <div class="col-md-3 col-sm-6 mb-2">
            <div class="stat-card bg-info">
                <small class="opacity-75">Converted</small>
                <h3><?= $stats['converted_count'] ?? 0 ?></h3>
            </div>
        </div>
    </div>
    
    <!-- Filters -->
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-body py-2">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small fw-semibold">Search</label>
                    <input type="text" name="search" class="form-control form-control-sm" value="<?= htmlspecialchars($search) ?>" placeholder="Name, ID, Email...">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold">Status</label>
                    <select name="status" class="form-select form-select-sm">
                        <option value="">All</option>
                        <option value="registered" <?= $filter_status === 'registered' ? 'selected' : '' ?>>Registered</option>
                        <option value="invoiced" <?= $filter_status === 'invoiced' ? 'selected' : '' ?>>Invoiced</option>
                        <option value="proof_submitted" <?= $filter_status === 'proof_submitted' ? 'selected' : '' ?>>Proof Submitted</option>
                        <option value="proof_requested" <?= $filter_status === 'proof_requested' ? 'selected' : '' ?>>Proof Requested</option>
                        <option value="cleared" <?= $filter_status === 'cleared' ? 'selected' : '' ?>>Cleared</option>
                        <option value="rejected" <?= $filter_status === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold">Student Type</label>
                    <select name="type" class="form-select form-select-sm">
                        <option value="">All</option>
                        <option value="system" <?= $filter_type === 'system' ? 'selected' : '' ?>>System</option>
                        <option value="external" <?= $filter_type === 'external' ? 'selected' : '' ?>>External</option>
                    </select>
                </div>
                <div class="col-md-1">
                    <button type="submit" class="btn btn-sm btn-primary w-100"><i class="bi bi-search me-1"></i>Filter</button>
                </div>
                <div class="col-md-1">
                    <a href="exam_clearance_students.php" class="btn btn-sm btn-outline-secondary w-100">Clear</a>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Students Table -->
    <div class="card shadow-sm border-0">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>Student ID</th>
                            <th>Full Name</th>
                            <th>Program</th>
                            <th>Source</th>
                            <th>Status</th>
                            <th>Invoiced</th>
                            <th>Paid</th>
                            <th>Applied</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($students)): ?>
                            <tr><td colspan="10" class="text-center text-muted py-4">No exam clearance students found.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($students as $i => $s): ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td><strong><?= htmlspecialchars($s['student_id']) ?></strong></td>
                            <td>
                                <?= htmlspecialchars($s['full_name']) ?>
                                <?php if ($s['email']): ?><br><small class="text-muted"><?= htmlspecialchars($s['email']) ?></small><?php endif; ?>
                            </td>
                            <td>
                                <span class="badge bg-<?= ($s['program_type'] ?? 'degree') === 'masters' ? 'info' : 'primary' ?>"><?= ucfirst($s['program_type'] ?? 'degree') ?></span>
                                <br><small class="text-muted"><?= htmlspecialchars($s['program'] ?: '—') ?></small>
                            </td>
                            <td>
                                <?php if ($s['is_system_student']): ?>
                                    <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>System</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">External</span>
                                <?php endif; ?>
                                <?php if (!empty($s['converted_to_student'])): ?>
                                    <br><span class="badge bg-info mt-1"><i class="bi bi-arrow-repeat me-1"></i>Converted</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                $sc = ['registered'=>'secondary','invoiced'=>'info','proof_submitted'=>'warning','proof_requested'=>'info','cleared'=>'success','rejected'=>'danger'];
                                ?>
                                <span class="badge bg-<?= $sc[$s['status']] ?? 'secondary' ?>"><?= ucfirst(str_replace('_', ' ', $s['status'])) ?></span>
                            </td>
                            <td>MWK <?= number_format($s['invoiced_amount'], 2) ?></td>
                            <td>MWK <?= number_format($s['total_approved'] ?? 0, 2) ?></td>
                            <td><small><?= date('M j, Y', strtotime($s['registered_at'])) ?></small></td>
                            <td>
                                <div class="d-flex gap-1 flex-wrap">
                                    <a href="../finance/exam_clearance_review.php?id=<?= $s['clearance_id'] ?>" class="btn btn-sm btn-outline-primary" title="Review"><i class="bi bi-eye"></i></a>
                                    
                                    <?php if (!$s['is_system_student'] && empty($s['converted_to_student'])): ?>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Convert this external student to a full system student? This action cannot be undone.')">
                                        <input type="hidden" name="action" value="convert_to_student">
                                        <input type="hidden" name="clearance_id" value="<?= $s['clearance_id'] ?>">
                                        <button class="btn btn-sm btn-info text-white" title="Convert to System Student"><i class="bi bi-person-plus"></i></button>
                                    </form>
                                    <?php endif; ?>
                                    
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Delete this student record? This cannot be undone.')">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="clearance_id" value="<?= $s['clearance_id'] ?>">
                                        <button class="btn btn-sm btn-outline-danger" title="Delete"><i class="bi bi-trash"></i></button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer text-muted small">
            Showing <?= count($students) ?> students
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
