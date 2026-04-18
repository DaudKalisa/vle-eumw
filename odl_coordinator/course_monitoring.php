<?php
/**
 * ODL Coordinator - Course Monitoring
 * Monitor course activity and engagement
 */

require_once '../includes/auth.php';
requireLogin();
requireRole(['odl_coordinator', 'admin', 'staff']);

$conn = getDbConnection();

// Check if vle_enrollments has status column
$has_status = false;
$col_check = $conn->query("SHOW COLUMNS FROM vle_enrollments LIKE 'status'");
if ($col_check && $col_check->num_rows > 0) {
    $has_status = true;
}

// Get all courses with statistics
$enrollment_join = $has_status 
    ? "LEFT JOIN vle_enrollments e ON c.course_id = e.course_id AND e.status = 'active'" 
    : "LEFT JOIN vle_enrollments e ON c.course_id = e.course_id";

$courses_sql = "
    SELECT 
        c.*,
        l.full_name as lecturer_name,
        COUNT(DISTINCT e.enrollment_id) as enrolled_count,
        COUNT(DISTINCT a.assignment_id) as assignment_count,
        COUNT(DISTINCT CASE WHEN sub.submission_id IS NOT NULL THEN sub.student_id END) as students_with_submissions
    FROM vle_courses c
    LEFT JOIN lecturers l ON c.lecturer_id = l.lecturer_id
    $enrollment_join
    LEFT JOIN vle_assignments a ON c.course_id = a.course_id
    LEFT JOIN vle_submissions sub ON a.assignment_id = sub.assignment_id
    GROUP BY c.course_id
    ORDER BY c.course_name
";

$courses = [];
$result = $conn->query($courses_sql);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $courses[] = $row;
    }
}

// Summary stats
$total_courses = count($courses);
$active_courses = array_filter($courses, fn($c) => ($c['status'] ?? 'active') === 'active');
$total_enrollments = array_sum(array_column($courses, 'enrolled_count'));
$total_assignments = array_sum(array_column($courses, 'assignment_count'));

$page_title = 'Course Monitoring';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Course Monitoring - ODL Coordinator</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f5f6fa; }
        .engagement-bar { height: 6px; background: #e9ecef; border-radius: 3px; }
        .engagement-fill { height: 100%; border-radius: 3px; }
    </style>
</head>
<body>
    <?php include 'header_nav.php'; ?>
    
    <div class="container-fluid py-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h1 class="h3 mb-1"><i class="bi bi-book me-2"></i>Course Monitoring</h1>
                <p class="text-muted mb-0">Track course activity and student engagement</p>
            </div>
        </div>
        
        <!-- Stats -->
        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="card bg-primary text-white h-100">
                    <div class="card-body text-center">
                        <div class="h2 mb-0"><?= number_format($total_courses) ?></div>
                        <small>Total Courses</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-success text-white h-100">
                    <div class="card-body text-center">
                        <div class="h2 mb-0"><?= number_format(count($active_courses)) ?></div>
                        <small>Active Courses</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-info text-white h-100">
                    <div class="card-body text-center">
                        <div class="h2 mb-0"><?= number_format($total_enrollments) ?></div>
                        <small>Total Enrollments</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-warning text-dark h-100">
                    <div class="card-body text-center">
                        <div class="h2 mb-0"><?= number_format($total_assignments) ?></div>
                        <small>Total Assignments</small>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Courses Table -->
        <div class="card">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h6 class="mb-0">All Courses</h6>
                <input type="text" id="searchCourse" class="form-control form-control-sm w-auto" placeholder="Search courses...">
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0" id="coursesTable">
                        <thead class="table-light">
                            <tr>
                                <th>Code</th>
                                <th>Course Name</th>
                                <th>Lecturer</th>
                                <th class="text-center">Students</th>
                                <th class="text-center">Assignments</th>
                                <th>Engagement</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($courses as $course): 
                                $engagement = $course['enrolled_count'] > 0 
                                    ? round(($course['students_with_submissions'] / $course['enrolled_count']) * 100) 
                                    : 0;
                                $engagementClass = $engagement >= 70 ? 'bg-success' : ($engagement >= 40 ? 'bg-warning' : 'bg-danger');
                            ?>
                            <tr>
                                <td><code><?= htmlspecialchars($course['course_code']) ?></code></td>
                                <td>
                                    <strong><?= htmlspecialchars($course['course_name']) ?></strong>
                                    <?php if (!empty($course['credits'])): ?>
                                    <div class="small text-muted"><?= $course['credits'] ?> credits</div>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($course['lecturer_name'] ?? 'Not assigned') ?></td>
                                <td class="text-center"><?= number_format($course['enrolled_count']) ?></td>
                                <td class="text-center"><?= number_format($course['assignment_count']) ?></td>
                                <td style="min-width: 120px;">
                                    <div class="d-flex align-items-center">
                                        <div class="engagement-bar flex-grow-1 me-2">
                                            <div class="engagement-fill <?= $engagementClass ?>" style="width: <?= $engagement ?>%"></div>
                                        </div>
                                        <small><?= $engagement ?>%</small>
                                    </div>
                                </td>
                                <td>
                                    <?php if (($course['status'] ?? 'active') === 'active'): ?>
                                    <span class="badge bg-success">Active</span>
                                    <?php else: ?>
                                    <span class="badge bg-secondary"><?= ucfirst($course['status']) ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.getElementById('searchCourse').addEventListener('keyup', function() {
            const filter = this.value.toLowerCase();
            const rows = document.querySelectorAll('#coursesTable tbody tr');
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(filter) ? '' : 'none';
            });
        });
    </script>
</body>
</html>
