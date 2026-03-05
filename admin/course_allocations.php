<?php
// course_allocations.php - Lecturer Course Allocations Management
require_once '../includes/auth.php';
requireLogin();
requireRole(['staff', 'admin']);

$conn = getDbConnection();

// Handle course assignment
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['assign_courses'])) {
        $lecturer_id = (int)$_POST['lecturer_id'];
        $course_ids = $_POST['course_ids'] ?? [];
        
        // Unassign all courses from this lecturer
        $stmt = $conn->prepare("UPDATE vle_courses SET lecturer_id = NULL WHERE lecturer_id = ?");
        $stmt->bind_param("i", $lecturer_id);
        $stmt->execute();
        
        // Assign selected courses
        $assigned_count = 0;
        if (!empty($course_ids)) {
            $stmt = $conn->prepare("UPDATE vle_courses SET lecturer_id = ? WHERE course_id = ?");
            foreach ($course_ids as $course_id) {
                $course_id = (int)$course_id;
                $stmt->bind_param("ii", $lecturer_id, $course_id);
                if ($stmt->execute()) {
                    $assigned_count++;
                }
            }
        }
        
        // Get lecturer name for message
        $name_stmt = $conn->prepare("SELECT full_name FROM lecturers WHERE lecturer_id = ?");
        $name_stmt->bind_param("i", $lecturer_id);
        $name_stmt->execute();
        $lec_name = $name_stmt->get_result()->fetch_assoc()['full_name'] ?? 'Lecturer';
        
        $success = "$assigned_count course(s) assigned to " . htmlspecialchars($lec_name) . ".";
    } elseif (isset($_POST['bulk_unassign'])) {
        $course_ids = $_POST['course_ids'] ?? [];
        $unassigned = 0;
        if (!empty($course_ids)) {
            $stmt = $conn->prepare("UPDATE vle_courses SET lecturer_id = NULL WHERE course_id = ?");
            foreach ($course_ids as $cid) {
                $cid_int = (int)$cid;
                $stmt->bind_param("i", $cid_int);
                if ($stmt->execute()) $unassigned++;
            }
        }
        $success = "$unassigned course(s) unassigned successfully.";
    }
}

// Get all lecturers
$lecturers = [];
$result = $conn->query("SELECT l.lecturer_id, l.full_name, l.department, l.position,
                        (SELECT COUNT(*) FROM vle_courses c WHERE c.lecturer_id = l.lecturer_id AND c.is_active = TRUE) as course_count
                        FROM lecturers l 
                        LEFT JOIN users u ON l.lecturer_id = u.related_lecturer_id 
                        WHERE l.is_active = TRUE AND (u.role = 'lecturer' OR FIND_IN_SET('lecturer', u.additional_roles) > 0 OR u.role IS NULL)
                        ORDER BY l.full_name");
while ($row = $result->fetch_assoc()) {
    $lecturers[] = $row;
}

// Get all active courses with lecturer info
$courses = [];
$result = $conn->query("SELECT c.course_id, c.course_code, c.course_name, c.program_of_study, c.year_of_study, c.lecturer_id,
                        l.full_name as lecturer_name
                        FROM vle_courses c 
                        LEFT JOIN lecturers l ON c.lecturer_id = l.lecturer_id
                        WHERE c.is_active = TRUE 
                        ORDER BY c.program_of_study, c.year_of_study, c.course_code");
while ($row = $result->fetch_assoc()) {
    $courses[] = $row;
}

// Statistics
$total_courses = count($courses);
$assigned_courses = count(array_filter($courses, fn($c) => !empty($c['lecturer_id'])));
$unassigned_courses = $total_courses - $assigned_courses;
$total_lecturers = count($lecturers);

// Group courses by program
$courses_by_program = [];
foreach ($courses as $c) {
    $prog = $c['program_of_study'] ?: 'No Program';
    $courses_by_program[$prog][] = $c;
}
ksort($courses_by_program);

// Get selected lecturer if any
$selected_lecturer_id = isset($_GET['lecturer_id']) ? (int)$_GET['lecturer_id'] : (isset($_POST['lecturer_id']) ? (int)$_POST['lecturer_id'] : 0);

// Note: Don't close $conn - header_nav.php needs it
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Course Allocations - Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
    <style>
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 1.25rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            border: 1px solid #e5e7eb;
            text-align: center;
        }
        .stat-card .stat-value {
            font-size: 2rem;
            font-weight: 700;
            line-height: 1;
        }
        .stat-card .stat-label {
            font-size: 0.85rem;
            color: #6b7280;
            margin-top: 0.25rem;
        }
        .allocation-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            border: 1px solid #e5e7eb;
            overflow: hidden;
        }
        .allocation-card .card-header-alloc {
            background: var(--vle-gradient-primary) !important;
            border: none;
            color: white;
            padding: 1rem 1.25rem;
        }
        .program-group {
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            margin-bottom: 1rem;
            overflow: hidden;
        }
        .program-group-header {
            background: #f8fafc;
            padding: 0.75rem 1rem;
            font-weight: 600;
            color: #374151;
            border-bottom: 1px solid #e5e7eb;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .program-group-header:hover {
            background: #f1f5f9;
        }
        .program-group-body {
            padding: 0;
        }
        .course-row {
            display: flex;
            align-items: center;
            padding: 0.65rem 1rem;
            border-bottom: 1px solid #f1f5f9;
            transition: background 0.15s;
        }
        .course-row:last-child {
            border-bottom: none;
        }
        .course-row:hover {
            background: #f8fafc;
        }
        .course-info {
            flex: 1;
        }
        .course-code {
            font-weight: 600;
            color: #1e293b;
        }
        .course-name {
            color: #6b7280;
            font-size: 0.9rem;
        }
        .lecturer-badge {
            font-size: 0.8rem;
            padding: 0.25rem 0.65rem;
            border-radius: 20px;
        }
        .lecturer-select-panel {
            position: sticky;
            top: 80px;
        }
        .lecturer-list-item {
            display: flex;
            align-items: center;
            padding: 0.6rem 0.75rem;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.15s;
            text-decoration: none;
            color: inherit;
            margin-bottom: 0.25rem;
        }
        .lecturer-list-item:hover {
            background: #f1f5f9;
            color: inherit;
        }
        .lecturer-list-item.active {
            background: var(--vle-primary, #4f46e5);
            color: white;
        }
        .lecturer-list-item .lec-avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: linear-gradient(135deg, #6366f1, #4f46e5);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 0.85rem;
            margin-right: 0.75rem;
            flex-shrink: 0;
        }
        .lecturer-list-item.active .lec-avatar {
            background: rgba(255,255,255,0.2);
        }
        .lec-info {
            flex: 1;
            min-width: 0;
        }
        .lec-info .lec-name {
            font-weight: 500;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .lec-info .lec-dept {
            font-size: 0.78rem;
            opacity: 0.7;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .lec-count {
            background: #e5e7eb;
            color: #374151;
            border-radius: 20px;
            padding: 0.15rem 0.5rem;
            font-size: 0.75rem;
            font-weight: 600;
            flex-shrink: 0;
        }
        .lecturer-list-item.active .lec-count {
            background: rgba(255,255,255,0.2);
            color: white;
        }
        .select-all-row {
            padding: 0.5rem 1rem;
            background: #f8fafc;
            border-bottom: 1px solid #e5e7eb;
        }
        @media (max-width: 991px) {
            .lecturer-select-panel {
                position: static;
            }
        }
    </style>
</head>

<body>
    <?php 
    $breadcrumbs = [['title' => 'Course Allocations']];
    include 'header_nav.php'; 
    ?>
    
    <div class="vle-content">
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
            <h2 class="vle-page-title"><i class="bi bi-person-lines-fill me-2"></i>Course Allocations</h2>
            <a href="dashboard.php" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i>Back to Dashboard
            </a>
        </div>

        <?php if (isset($success)): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle me-2"></i><?php echo $success; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        <?php if (isset($error)): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="bi bi-exclamation-circle me-2"></i><?php echo $error; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Statistics Cards -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-md-3">
                <div class="stat-card">
                    <div class="stat-value text-primary"><?php echo $total_courses; ?></div>
                    <div class="stat-label">Total Courses</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card">
                    <div class="stat-value text-success"><?php echo $assigned_courses; ?></div>
                    <div class="stat-label">Assigned</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card">
                    <div class="stat-value text-warning"><?php echo $unassigned_courses; ?></div>
                    <div class="stat-label">Unassigned</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card">
                    <div class="stat-value text-info"><?php echo $total_lecturers; ?></div>
                    <div class="stat-label">Lecturers</div>
                </div>
            </div>
        </div>

        <div class="row g-4">
            <!-- Lecturer Selection Panel -->
            <div class="col-lg-4">
                <div class="allocation-card">
                    <div class="card-header-alloc">
                        <h5 class="mb-0"><i class="bi bi-people-fill me-2"></i>Select Lecturer</h5>
                    </div>
                    <div class="p-3">
                        <input type="text" id="lecturerSearch" class="form-control mb-3" placeholder="Search lecturers...">
                        <div class="lecturer-select-panel" style="max-height: 500px; overflow-y: auto;">
                            <!-- All Courses (overview) -->
                            <a href="?lecturer_id=0" class="lecturer-list-item <?php echo $selected_lecturer_id === 0 ? 'active' : ''; ?>">
                                <div class="lec-avatar" style="background: linear-gradient(135deg, #64748b, #475569);">
                                    <i class="bi bi-grid-fill"></i>
                                </div>
                                <div class="lec-info">
                                    <div class="lec-name">All Courses Overview</div>
                                    <div class="lec-dept">View all allocations</div>
                                </div>
                                <span class="lec-count"><?php echo $total_courses; ?></span>
                            </a>
                            <hr class="my-2">
                            <?php foreach ($lecturers as $lec): ?>
                                <a href="?lecturer_id=<?php echo $lec['lecturer_id']; ?>" 
                                   class="lecturer-list-item <?php echo ($selected_lecturer_id == $lec['lecturer_id']) ? 'active' : ''; ?>"
                                   data-name="<?php echo htmlspecialchars(strtolower($lec['full_name'])); ?>">
                                    <div class="lec-avatar">
                                        <?php echo strtoupper(substr($lec['full_name'], 0, 1)); ?>
                                    </div>
                                    <div class="lec-info">
                                        <div class="lec-name"><?php echo htmlspecialchars($lec['full_name']); ?></div>
                                        <div class="lec-dept"><?php echo htmlspecialchars($lec['department'] ?: 'No department'); ?></div>
                                    </div>
                                    <span class="lec-count"><?php echo $lec['course_count']; ?></span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Course Assignment Panel -->
            <div class="col-lg-8">
                <?php if ($selected_lecturer_id > 0): ?>
                    <?php 
                    // Find the selected lecturer
                    $sel_lec = null;
                    foreach ($lecturers as $l) {
                        if ($l['lecturer_id'] == $selected_lecturer_id) { $sel_lec = $l; break; }
                    }
                    ?>
                    <?php if ($sel_lec): ?>
                        <div class="allocation-card">
                            <div class="card-header-alloc d-flex justify-content-between align-items-center">
                                <h5 class="mb-0">
                                    <i class="bi bi-book-fill me-2"></i>Assign Courses to <?php echo htmlspecialchars($sel_lec['full_name']); ?>
                                </h5>
                                <span class="badge bg-light text-dark"><?php echo $sel_lec['course_count']; ?> assigned</span>
                            </div>
                            <form method="POST" id="assignForm">
                                <input type="hidden" name="lecturer_id" value="<?php echo $sel_lec['lecturer_id']; ?>">
                                
                                <div class="select-all-row d-flex justify-content-between align-items-center">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="selectAll">
                                        <label class="form-check-label fw-semibold" for="selectAll">Select / Deselect All</label>
                                    </div>
                                    <div>
                                        <input type="text" id="courseSearch" class="form-control form-control-sm" placeholder="Filter courses..." style="width: 200px;">
                                    </div>
                                </div>
                                
                                <div class="p-3" id="courseContainer">
                                    <?php if (empty($courses_by_program)): ?>
                                        <p class="text-muted text-center py-3">No active courses available.</p>
                                    <?php else: ?>
                                        <?php foreach ($courses_by_program as $program => $prog_courses): ?>
                                            <div class="program-group" data-program="<?php echo htmlspecialchars(strtolower($program)); ?>">
                                                <div class="program-group-header" onclick="toggleProgramGroup(this)">
                                                    <span>
                                                        <i class="bi bi-mortarboard me-2"></i><?php echo htmlspecialchars($program); ?>
                                                        <span class="badge bg-secondary ms-2"><?php echo count($prog_courses); ?></span>
                                                    </span>
                                                    <i class="bi bi-chevron-down"></i>
                                                </div>
                                                <div class="program-group-body">
                                                    <?php foreach ($prog_courses as $course): ?>
                                                        <div class="course-row" data-search="<?php echo htmlspecialchars(strtolower($course['course_code'] . ' ' . $course['course_name'])); ?>">
                                                            <div class="form-check me-3">
                                                                <input class="form-check-input course-checkbox" type="checkbox" 
                                                                       name="course_ids[]" 
                                                                       value="<?php echo $course['course_id']; ?>"
                                                                       id="course_<?php echo $course['course_id']; ?>"
                                                                       <?php echo ($course['lecturer_id'] == $sel_lec['lecturer_id']) ? 'checked' : ''; ?>>
                                                            </div>
                                                            <label class="course-info" for="course_<?php echo $course['course_id']; ?>" style="cursor:pointer;">
                                                                <span class="course-code"><?php echo htmlspecialchars($course['course_code']); ?></span>
                                                                <span class="course-name ms-2"><?php echo htmlspecialchars($course['course_name']); ?></span>
                                                                <br>
                                                                <small class="text-muted">Year <?php echo $course['year_of_study']; ?></small>
                                                            </label>
                                                            <div class="ms-2">
                                                                <?php if ($course['lecturer_id'] == $sel_lec['lecturer_id']): ?>
                                                                    <span class="lecturer-badge badge bg-success">Yours</span>
                                                                <?php elseif (!empty($course['lecturer_id'])): ?>
                                                                    <span class="lecturer-badge badge bg-warning text-dark" title="<?php echo htmlspecialchars($course['lecturer_name']); ?>">
                                                                        <?php echo htmlspecialchars($course['lecturer_name']); ?>
                                                                    </span>
                                                                <?php else: ?>
                                                                    <span class="lecturer-badge badge bg-light text-muted">Unassigned</span>
                                                                <?php endif; ?>
                                                            </div>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="p-3 border-top d-flex justify-content-between align-items-center bg-light">
                                    <span class="text-muted"><span id="selectedCount">0</span> course(s) selected</span>
                                    <button type="submit" name="assign_courses" class="btn btn-primary">
                                        <i class="bi bi-save me-1"></i>Save Allocations
                                    </button>
                                </div>
                            </form>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-warning">Lecturer not found.</div>
                    <?php endif; ?>
                <?php else: ?>
                    <!-- Overview of all allocations -->
                    <div class="allocation-card">
                        <div class="card-header-alloc d-flex justify-content-between align-items-center">
                            <h5 class="mb-0"><i class="bi bi-grid-fill me-2"></i>All Course Allocations</h5>
                            <div>
                                <input type="text" id="overviewSearch" class="form-control form-control-sm" placeholder="Search courses..." style="width: 200px; display:inline-block;">
                                <select id="filterStatus" class="form-select form-select-sm d-inline-block ms-2" style="width: 140px;">
                                    <option value="all">All Status</option>
                                    <option value="assigned">Assigned</option>
                                    <option value="unassigned">Unassigned</option>
                                </select>
                            </div>
                        </div>
                        <div class="p-3">
                            <?php if (empty($courses_by_program)): ?>
                                <p class="text-muted text-center py-3">No active courses found.</p>
                            <?php else: ?>
                                <?php foreach ($courses_by_program as $program => $prog_courses): ?>
                                    <div class="program-group" data-program="<?php echo htmlspecialchars(strtolower($program)); ?>">
                                        <div class="program-group-header" onclick="toggleProgramGroup(this)">
                                            <span>
                                                <i class="bi bi-mortarboard me-2"></i><?php echo htmlspecialchars($program); ?>
                                                <span class="badge bg-secondary ms-2"><?php echo count($prog_courses); ?></span>
                                            </span>
                                            <i class="bi bi-chevron-down"></i>
                                        </div>
                                        <div class="program-group-body">
                                            <?php foreach ($prog_courses as $course): ?>
                                                <div class="course-row overview-row" 
                                                     data-search="<?php echo htmlspecialchars(strtolower($course['course_code'] . ' ' . $course['course_name'] . ' ' . ($course['lecturer_name'] ?? ''))); ?>"
                                                     data-status="<?php echo !empty($course['lecturer_id']) ? 'assigned' : 'unassigned'; ?>">
                                                    <div class="course-info">
                                                        <span class="course-code"><?php echo htmlspecialchars($course['course_code']); ?></span>
                                                        <span class="course-name ms-2"><?php echo htmlspecialchars($course['course_name']); ?></span>
                                                        <br>
                                                        <small class="text-muted">Year <?php echo $course['year_of_study']; ?></small>
                                                    </div>
                                                    <div class="ms-2">
                                                        <?php if (!empty($course['lecturer_id'])): ?>
                                                            <span class="lecturer-badge badge bg-success">
                                                                <i class="bi bi-person-fill me-1"></i><?php echo htmlspecialchars($course['lecturer_name']); ?>
                                                            </span>
                                                        <?php else: ?>
                                                            <span class="lecturer-badge badge bg-warning text-dark">
                                                                <i class="bi bi-person-x me-1"></i>Unassigned
                                                            </span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Toggle program group collapse
        function toggleProgramGroup(header) {
            const body = header.nextElementSibling;
            const icon = header.querySelector('.bi-chevron-down, .bi-chevron-up');
            if (body.style.display === 'none') {
                body.style.display = '';
                icon.classList.replace('bi-chevron-up', 'bi-chevron-down');
            } else {
                body.style.display = 'none';
                icon.classList.replace('bi-chevron-down', 'bi-chevron-up');
            }
        }

        // Lecturer search
        document.getElementById('lecturerSearch')?.addEventListener('input', function() {
            const term = this.value.toLowerCase();
            document.querySelectorAll('.lecturer-list-item[data-name]').forEach(item => {
                item.style.display = item.dataset.name.includes(term) ? '' : 'none';
            });
        });

        // Course search (assignment mode)
        document.getElementById('courseSearch')?.addEventListener('input', function() {
            const term = this.value.toLowerCase();
            document.querySelectorAll('#courseContainer .course-row').forEach(row => {
                row.style.display = row.dataset.search.includes(term) ? '' : 'none';
            });
        });

        // Overview search & filter
        document.getElementById('overviewSearch')?.addEventListener('input', filterOverview);
        document.getElementById('filterStatus')?.addEventListener('change', filterOverview);

        function filterOverview() {
            const term = (document.getElementById('overviewSearch')?.value || '').toLowerCase();
            const status = document.getElementById('filterStatus')?.value || 'all';
            
            document.querySelectorAll('.overview-row').forEach(row => {
                const matchText = !term || row.dataset.search.includes(term);
                const matchStatus = status === 'all' || row.dataset.status === status;
                row.style.display = (matchText && matchStatus) ? '' : 'none';
            });
        }

        // Select all checkbox
        document.getElementById('selectAll')?.addEventListener('change', function() {
            const checked = this.checked;
            document.querySelectorAll('.course-checkbox').forEach(cb => {
                // Only toggle visible checkboxes
                if (cb.closest('.course-row').style.display !== 'none') {
                    cb.checked = checked;
                }
            });
            updateSelectedCount();
        });

        // Update selected count
        function updateSelectedCount() {
            const count = document.querySelectorAll('.course-checkbox:checked').length;
            const el = document.getElementById('selectedCount');
            if (el) el.textContent = count;
        }

        document.querySelectorAll('.course-checkbox').forEach(cb => {
            cb.addEventListener('change', updateSelectedCount);
        });

        // Initialize count
        updateSelectedCount();
    </script>
</body>
</html>
