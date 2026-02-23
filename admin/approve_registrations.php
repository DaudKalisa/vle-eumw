<?php
// admin/approve_registrations.php - Admin interface for approving student course registrations
require_once '../includes/auth.php';
requireLogin();
requireRole(['staff']);

$conn = getDbConnection();
$user = getCurrentUser();

$success = '';
$error = '';

// Handle approval/rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['approve_request'])) {
        $request_id = (int)$_POST['request_id'];
        
        // Get request details
        $request_stmt = $conn->prepare("SELECT r.*, s.full_name as student_name, s.program, s.year_of_study, s.semester,
                                        c.course_name, c.course_code, c.program_of_study, c.year_of_study as course_year
                                        FROM course_registration_requests r
                                        INNER JOIN students s ON r.student_id = s.student_id
                                        INNER JOIN vle_courses c ON r.course_id = c.course_id
                                        WHERE r.request_id = ? AND r.status = 'pending'");
        $request_stmt->bind_param("i", $request_id);
        $request_stmt->execute();
        $request_result = $request_stmt->get_result();
        
        if ($request_result->num_rows === 0) {
            $error = "Invalid or already processed request!";
        } else {
            $request = $request_result->fetch_assoc();
            
            // Check if already enrolled
            $enrolled_check = $conn->prepare("SELECT enrollment_id FROM vle_enrollments WHERE student_id = ? AND course_id = ?");
            $enrolled_check->bind_param("si", $request['student_id'], $request['course_id']);
            $enrolled_check->execute();
            
            if ($enrolled_check->get_result()->num_rows > 0) {
                // Update request status to rejected
                $update_stmt = $conn->prepare("UPDATE course_registration_requests 
                                              SET status = 'rejected', reviewed_by = ?, reviewed_date = NOW(), 
                                              admin_notes = 'Student already enrolled in this course' 
                                              WHERE request_id = ?");
                $update_stmt->bind_param("ii", $user['user_id'], $request_id);
                $update_stmt->execute();
                
                $error = "Student is already enrolled in this course!";
            } else {
                // Check 7-course limit
                $count_check = $conn->prepare("SELECT COUNT(*) as course_count FROM vle_enrollments e 
                                              INNER JOIN semester_courses sc ON e.course_id = sc.course_id 
                                              WHERE e.student_id = ? AND sc.semester = ? AND sc.academic_year = ?");
                $count_check->bind_param("sss", $request['student_id'], $request['semester'], $request['academic_year']);
                $count_check->execute();
                $count_result = $count_check->get_result()->fetch_assoc();
                
                if ($count_result['course_count'] >= 7) {
                    // Update request status to rejected
                    $update_stmt = $conn->prepare("UPDATE course_registration_requests 
                                                  SET status = 'rejected', reviewed_by = ?, reviewed_date = NOW(), 
                                                  admin_notes = 'Student has reached 7-course limit for this semester' 
                                                  WHERE request_id = ?");
                    $update_stmt->bind_param("ii", $user['user_id'], $request_id);
                    $update_stmt->execute();
                    
                    $error = "Student has reached the 7-course limit for " . htmlspecialchars($request['semester']) . "!";
                } else {
                    // Begin transaction
                    $conn->begin_transaction();
                    
                    try {
                        // Insert into vle_enrollments
                        $enroll_stmt = $conn->prepare("INSERT INTO vle_enrollments (student_id, course_id, enrollment_date) VALUES (?, ?, NOW())");
                        $enroll_stmt->bind_param("si", $request['student_id'], $request['course_id']);
                        $enroll_stmt->execute();
                        
                        // Update request status to approved
                        $update_stmt = $conn->prepare("UPDATE course_registration_requests 
                                                      SET status = 'approved', reviewed_by = ?, reviewed_date = NOW() 
                                                      WHERE request_id = ?");
                        $update_stmt->bind_param("ii", $user['user_id'], $request_id);
                        $update_stmt->execute();
                        
                        $conn->commit();
                        $success = "Registration approved! " . htmlspecialchars($request['student_name']) . 
                                  " has been enrolled in " . htmlspecialchars($request['course_name']) . ".";
                    } catch (Exception $e) {
                        $conn->rollback();
                        $error = "Error approving registration: " . $e->getMessage();
                    }
                }
            }
        }
    } elseif (isset($_POST['reject_request'])) {
        $request_id = (int)$_POST['request_id'];
        $admin_notes = trim($_POST['admin_notes'] ?? '');
        
        // Update request status to rejected
        $update_stmt = $conn->prepare("UPDATE course_registration_requests 
                                      SET status = 'rejected', reviewed_by = ?, reviewed_date = NOW(), admin_notes = ? 
                                      WHERE request_id = ? AND status = 'pending'");
        $update_stmt->bind_param("isi", $user['user_id'], $admin_notes, $request_id);
        
        if ($update_stmt->execute() && $update_stmt->affected_rows > 0) {
            $success = "Registration request rejected.";
        } else {
            $error = "Error rejecting request or request already processed!";
        }
    } elseif (isset($_POST['bulk_approve'])) {
        $selected_requests = $_POST['selected_requests'] ?? [];
        
        if (empty($selected_requests)) {
            $error = "No requests selected!";
        } else {
            $approved_count = 0;
            $rejected_count = 0;
            $errors = [];
            
            foreach ($selected_requests as $request_id) {
                $request_id = (int)$request_id;
                
                // Get request details
                $request_stmt = $conn->prepare("SELECT r.*, s.full_name as student_name 
                                                FROM course_registration_requests r
                                                INNER JOIN students s ON r.student_id = s.student_id
                                                WHERE r.request_id = ? AND r.status = 'pending'");
                $request_stmt->bind_param("i", $request_id);
                $request_stmt->execute();
                $request_result = $request_stmt->get_result();
                
                if ($request_result->num_rows === 0) continue;
                
                $request = $request_result->fetch_assoc();
                
                // Check if already enrolled
                $enrolled_check = $conn->prepare("SELECT enrollment_id FROM vle_enrollments WHERE student_id = ? AND course_id = ?");
                $enrolled_check->bind_param("si", $request['student_id'], $request['course_id']);
                $enrolled_check->execute();
                
                if ($enrolled_check->get_result()->num_rows > 0) {
                    $rejected_count++;
                    continue;
                }
                
                // Check 7-course limit
                $count_check = $conn->prepare("SELECT COUNT(*) as course_count FROM vle_enrollments e 
                                              INNER JOIN semester_courses sc ON e.course_id = sc.course_id 
                                              WHERE e.student_id = ? AND sc.semester = ? AND sc.academic_year = ?");
                $count_check->bind_param("sss", $request['student_id'], $request['semester'], $request['academic_year']);
                $count_check->execute();
                $count_result = $count_check->get_result()->fetch_assoc();
                
                if ($count_result['course_count'] >= 7) {
                    $rejected_count++;
                    continue;
                }
                
                // Begin transaction
                $conn->begin_transaction();
                
                try {
                    // Insert into vle_enrollments
                    $enroll_stmt = $conn->prepare("INSERT INTO vle_enrollments (student_id, course_id, enrollment_date) VALUES (?, ?, NOW())");
                    $enroll_stmt->bind_param("si", $request['student_id'], $request['course_id']);
                    $enroll_stmt->execute();
                    
                    // Update request status
                    $update_stmt = $conn->prepare("UPDATE course_registration_requests 
                                                  SET status = 'approved', reviewed_by = ?, reviewed_date = NOW() 
                                                  WHERE request_id = ?");
                    $update_stmt->bind_param("ii", $user['user_id'], $request_id);
                    $update_stmt->execute();
                    
                    $conn->commit();
                    $approved_count++;
                } catch (Exception $e) {
                    $conn->rollback();
                    $errors[] = "Error approving request for " . htmlspecialchars($request['student_name']);
                }
            }
            
            $success = "Bulk approval complete! Approved: $approved_count, Skipped: $rejected_count";
            if (!empty($errors)) {
                $error = implode(", ", $errors);
            }
        }
    }
}

// Get filter parameters
$filter_status = $_GET['status'] ?? 'pending';
$filter_program = $_GET['program'] ?? '';
$filter_semester = $_GET['semester'] ?? '';

// Build query for registration requests
$query = "SELECT r.*, 
          s.student_id, s.full_name as student_name, s.program, s.year_of_study, s.semester as student_semester,
          c.course_id, c.course_name, c.course_code, c.program_of_study as course_program, 
          c.year_of_study as course_year,
          l.full_name as lecturer_name,
          u.username as reviewer_name
          FROM course_registration_requests r
          INNER JOIN students s ON r.student_id = s.student_id
          INNER JOIN vle_courses c ON r.course_id = c.course_id
          LEFT JOIN lecturers l ON c.lecturer_id = l.lecturer_id
          LEFT JOIN users u ON r.reviewed_by = u.user_id
          WHERE 1=1";

$params = [];
$types = '';

if ($filter_status !== 'all') {
    $query .= " AND r.status = ?";
    $params[] = $filter_status;
    $types .= 's';
}

if (!empty($filter_program)) {
    $query .= " AND s.program = ?";
    $params[] = $filter_program;
    $types .= 's';
}

if (!empty($filter_semester)) {
    $query .= " AND r.semester = ?";
    $params[] = $filter_semester;
    $types .= 's';
}

$query .= " ORDER BY r.request_date DESC";

$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$requests = $stmt->get_result();

// Get unique programs and semesters for filters
$programs_result = $conn->query("SELECT DISTINCT program FROM students WHERE is_active = TRUE ORDER BY program");
$semesters_result = $conn->query("SELECT DISTINCT semester FROM course_registration_requests ORDER BY semester");

// Get statistics
$stats_query = "SELECT 
                COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_count,
                COUNT(CASE WHEN status = 'approved' THEN 1 END) as approved_count,
                COUNT(CASE WHEN status = 'rejected' THEN 1 END) as rejected_count
                FROM course_registration_requests";
$stats = $conn->query($stats_query)->fetch_assoc();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Approve Course Registrations - Admin VLE</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <style>
        .stats-card {
            border-left: 4px solid;
            transition: transform 0.2s;
        }
        .stats-card:hover {
            transform: translateY(-2px);
        }
        .stats-card.pending { border-left-color: #ffc107; }
        .stats-card.approved { border-left-color: #198754; }
        .stats-card.rejected { border-left-color: #dc3545; }
        
        .request-card {
            transition: all 0.3s;
        }
        .request-card:hover {
            box-shadow: 0 4px 8px rgba(0,0,0,0.15);
        }
        .match-indicator {
            font-size: 0.75rem;
            padding: 0.25rem 0.5rem;
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container-fluid">
            <a class="navbar-brand" href="dashboard.php">
                <i class="bi bi-shield-check"></i> Admin Panel
            </a>
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="dashboard.php">Dashboard</a>
                <a class="nav-link" href="../logout.php">Logout</a>
            </div>
        </div>
    </nav>

    <div class="container-fluid mt-4">
        <!-- Header -->
        <div class="row mb-4">
            <div class="col-md-12">
                <h2><i class="bi bi-clipboard-check"></i> Course Registration Approvals</h2>
                <p class="text-muted">Review and approve student course registration requests</p>
            </div>
        </div>

        <!-- Messages -->
        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle"></i> <?= $success ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="bi bi-exclamation-triangle"></i> <?= $error ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Statistics -->
        <div class="row mb-4">
            <div class="col-md-4">
                <div class="card stats-card pending shadow-sm">
                    <div class="card-body">
                        <h6 class="card-subtitle mb-2 text-muted">Pending Requests</h6>
                        <h2 class="card-title text-warning"><?= $stats['pending_count'] ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card stats-card approved shadow-sm">
                    <div class="card-body">
                        <h6 class="card-subtitle mb-2 text-muted">Approved</h6>
                        <h2 class="card-title text-success"><?= $stats['approved_count'] ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card stats-card rejected shadow-sm">
                    <div class="card-body">
                        <h6 class="card-subtitle mb-2 text-muted">Rejected</h6>
                        <h2 class="card-title text-danger"><?= $stats['rejected_count'] ?></h2>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="card shadow-sm mb-4">
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select">
                            <option value="all" <?= $filter_status === 'all' ? 'selected' : '' ?>>All</option>
                            <option value="pending" <?= $filter_status === 'pending' ? 'selected' : '' ?>>Pending</option>
                            <option value="approved" <?= $filter_status === 'approved' ? 'selected' : '' ?>>Approved</option>
                            <option value="rejected" <?= $filter_status === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Program</label>
                        <select name="program" class="form-select">
                            <option value="">All Programs</option>
                            <?php while ($prog = $programs_result->fetch_assoc()): ?>
                                <option value="<?= htmlspecialchars($prog['program']) ?>" 
                                        <?= $filter_program === $prog['program'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($prog['program']) ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Semester</label>
                        <select name="semester" class="form-select">
                            <option value="">All Semesters</option>
                            <?php while ($sem = $semesters_result->fetch_assoc()): ?>
                                <option value="<?= htmlspecialchars($sem['semester']) ?>" 
                                        <?= $filter_semester === $sem['semester'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($sem['semester']) ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">&nbsp;</label>
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-funnel"></i> Apply Filters
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Bulk Actions -->
        <?php if ($filter_status === 'pending' && $requests->num_rows > 0): ?>
            <form method="POST" id="bulkForm">
                <div class="card shadow-sm mb-3">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <button type="button" id="selectAll" class="btn btn-sm btn-outline-primary">
                                    <i class="bi bi-check-square"></i> Select All
                                </button>
                                <button type="button" id="deselectAll" class="btn btn-sm btn-outline-secondary">
                                    <i class="bi bi-square"></i> Deselect All
                                </button>
                                <span id="selectedCount" class="ms-3 text-muted">0 selected</span>
                            </div>
                            <button type="submit" name="bulk_approve" class="btn btn-success" id="bulkApproveBtn" disabled>
                                <i class="bi bi-check-circle"></i> Approve Selected
                            </button>
                        </div>
                    </div>
                </div>
        <?php endif; ?>

        <!-- Requests List -->
        <div class="card shadow-sm">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="bi bi-list-check"></i> Registration Requests</h5>
            </div>
            <div class="card-body">
                <?php if ($requests->num_rows > 0): ?>
                    <div class="row">
                        <?php while ($req = $requests->fetch_assoc()): ?>
                            <div class="col-md-6 mb-3">
                                <div class="card request-card h-100">
                                    <div class="card-body">
                                        <!-- Selection Checkbox -->
                                        <?php if ($req['status'] === 'pending'): ?>
                                            <div class="form-check mb-2">
                                                <input class="form-check-input request-checkbox" type="checkbox" 
                                                       name="selected_requests[]" value="<?= $req['request_id'] ?>" 
                                                       id="req<?= $req['request_id'] ?>">
                                                <label class="form-check-label" for="req<?= $req['request_id'] ?>">
                                                    <strong>Select for bulk approval</strong>
                                                </label>
                                            </div>
                                        <?php endif; ?>

                                        <!-- Student Info -->
                                        <h6 class="card-title">
                                            <i class="bi bi-person-circle"></i> <?= htmlspecialchars($req['student_name']) ?>
                                        </h6>
                                        <div class="small text-muted mb-2">
                                            ID: <?= htmlspecialchars($req['student_id']) ?> | 
                                            <?= htmlspecialchars($req['program']) ?> | 
                                            Year <?= $req['year_of_study'] ?> | 
                                            Semester <?= $req['student_semester'] ?>
                                        </div>

                                        <hr>

                                        <!-- Course Info -->
                                        <h6 class="mb-1">
                                            <i class="bi bi-book"></i> <?= htmlspecialchars($req['course_code']) ?> - 
                                            <?= htmlspecialchars($req['course_name']) ?>
                                        </h6>
                                        <div class="small text-muted mb-2">
                                            <div><i class="bi bi-person-badge"></i> Lecturer: <?= htmlspecialchars($req['lecturer_name'] ?? 'Not Assigned') ?></div>
                                            <div><i class="bi bi-calendar3"></i> <?= htmlspecialchars($req['semester']) ?> <?= htmlspecialchars($req['academic_year']) ?></div>
                                            <div><i class="bi bi-award"></i> Credits: <?= htmlspecialchars($req['credits'] ?? 'N/A') ?></div>
                                        </div>

                                        <!-- Compatibility Check -->
                                        <div class="mb-2">
                                            <?php 
                                            $program_match = ($req['program'] === $req['course_program']);
                                            $year_match = ($req['year_of_study'] == $req['course_year']);
                                            ?>
                                            <?php if ($program_match && $year_match): ?>
                                                <span class="badge bg-success match-indicator">
                                                    <i class="bi bi-check-circle"></i> Program & Year Match
                                                </span>
                                            <?php else: ?>
                                                <?php if (!$program_match): ?>
                                                    <span class="badge bg-warning text-dark match-indicator">
                                                        <i class="bi bi-exclamation-triangle"></i> Program Mismatch
                                                    </span>
                                                <?php endif; ?>
                                                <?php if (!$year_match): ?>
                                                    <span class="badge bg-warning text-dark match-indicator">
                                                        <i class="bi bi-exclamation-triangle"></i> Year Mismatch
                                                    </span>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </div>

                                        <!-- Request Details -->
                                        <div class="small mb-2">
                                            <div><strong>Requested:</strong> <?= date('M d, Y g:i A', strtotime($req['request_date'])) ?></div>
                                            <?php if ($req['status'] !== 'pending'): ?>
                                                <div><strong>Reviewed by:</strong> <?= htmlspecialchars($req['reviewer_name'] ?? 'Unknown') ?></div>
                                                <div><strong>Reviewed:</strong> <?= date('M d, Y g:i A', strtotime($req['reviewed_date'])) ?></div>
                                            <?php endif; ?>
                                        </div>

                                        <!-- Status Badge -->
                                        <div class="mb-3">
                                            <?php if ($req['status'] === 'pending'): ?>
                                                <span class="badge bg-warning text-dark">
                                                    <i class="bi bi-clock"></i> Pending Review
                                                </span>
                                            <?php elseif ($req['status'] === 'approved'): ?>
                                                <span class="badge bg-success">
                                                    <i class="bi bi-check-circle"></i> Approved
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-danger">
                                                    <i class="bi bi-x-circle"></i> Rejected
                                                </span>
                                            <?php endif; ?>
                                        </div>

                                        <!-- Admin Notes -->
                                        <?php if ($req['admin_notes']): ?>
                                            <div class="alert alert-info small mb-2">
                                                <strong>Admin Notes:</strong> <?= htmlspecialchars($req['admin_notes']) ?>
                                            </div>
                                        <?php endif; ?>

                                        <!-- Actions -->
                                        <?php if ($req['status'] === 'pending'): ?>
                                            <div class="btn-group w-100" role="group">
                                                <form method="POST" class="flex-fill" style="display: inline;">
                                                    <input type="hidden" name="request_id" value="<?= $req['request_id'] ?>">
                                                    <button type="submit" name="approve_request" class="btn btn-success w-100">
                                                        <i class="bi bi-check-circle"></i> Approve
                                                    </button>
                                                </form>
                                                <button type="button" class="btn btn-danger" data-bs-toggle="modal" 
                                                        data-bs-target="#rejectModal<?= $req['request_id'] ?>">
                                                    <i class="bi bi-x-circle"></i> Reject
                                                </button>
                                            </div>

                                            <!-- Reject Modal -->
                                            <div class="modal fade" id="rejectModal<?= $req['request_id'] ?>" tabindex="-1">
                                                <div class="modal-dialog">
                                                    <div class="modal-content">
                                                        <div class="modal-header">
                                                            <h5 class="modal-title">Reject Registration Request</h5>
                                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                        </div>
                                                        <form method="POST">
                                                            <div class="modal-body">
                                                                <input type="hidden" name="request_id" value="<?= $req['request_id'] ?>">
                                                                <p>Are you sure you want to reject this request?</p>
                                                                <p class="text-muted small">
                                                                    Student: <strong><?= htmlspecialchars($req['student_name']) ?></strong><br>
                                                                    Course: <strong><?= htmlspecialchars($req['course_name']) ?></strong>
                                                                </p>
                                                                <div class="mb-3">
                                                                    <label class="form-label">Reason (optional)</label>
                                                                    <textarea name="admin_notes" class="form-control" rows="3" 
                                                                              placeholder="Enter reason for rejection..."></textarea>
                                                                </div>
                                                            </div>
                                                            <div class="modal-footer">
                                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                                <button type="submit" name="reject_request" class="btn btn-danger">
                                                                    <i class="bi bi-x-circle"></i> Reject Request
                                                                </button>
                                                            </div>
                                                        </form>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    </div>
                <?php else: ?>
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle"></i> No registration requests found matching the selected filters.
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($filter_status === 'pending' && $requests->num_rows > 0): ?>
            </form>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Bulk selection functionality
        const checkboxes = document.querySelectorAll('.request-checkbox');
        const selectAllBtn = document.getElementById('selectAll');
        const deselectAllBtn = document.getElementById('deselectAll');
        const selectedCount = document.getElementById('selectedCount');
        const bulkApproveBtn = document.getElementById('bulkApproveBtn');

        function updateCount() {
            const checked = document.querySelectorAll('.request-checkbox:checked').length;
            selectedCount.textContent = checked + ' selected';
            bulkApproveBtn.disabled = checked === 0;
        }

        if (selectAllBtn) {
            selectAllBtn.addEventListener('click', () => {
                checkboxes.forEach(cb => cb.checked = true);
                updateCount();
            });
        }

        if (deselectAllBtn) {
            deselectAllBtn.addEventListener('click', () => {
                checkboxes.forEach(cb => cb.checked = false);
                updateCount();
            });
        }

        checkboxes.forEach(cb => {
            cb.addEventListener('change', updateCount);
        });

        // Confirm bulk approval
        if (bulkApproveBtn) {
            document.getElementById('bulkForm').addEventListener('submit', (e) => {
                const checked = document.querySelectorAll('.request-checkbox:checked').length;
                if (!confirm(`Are you sure you want to approve ${checked} registration request(s)?`)) {
                    e.preventDefault();
                }
            });
        }
    </script>
</body>
</html>
