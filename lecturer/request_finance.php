<?php
// request_finance.php - Lecturer Finance Request System
require_once '../includes/auth.php';
requireLogin();
requireRole(['lecturer']);

$conn = getDbConnection();
$lecturer_id = $_SESSION['vle_related_id'];

// Get lecturer profile data
$stmt = $conn->prepare("SELECT * FROM lecturers WHERE lecturer_id = ?");
$stmt->bind_param("s", $lecturer_id);
$stmt->execute();
$lecturer = $stmt->get_result()->fetch_assoc();

if (!$lecturer) {
    die("Error: Lecturer profile not found.");
}

// Determine hourly rate based on position
$position = strtolower($lecturer['position'] ?? '');
if (strpos($position, 'senior lecturer') !== false) {
    $hourly_rate = 8500;
    $rate_label = 'Senior Lecturer (K8,500/hr)';
} elseif (strpos($position, 'lecturer') !== false && strpos($position, 'associate') === false) {
    $hourly_rate = 6500;
    $rate_label = 'Lecturer (K6,500/hr)';
} elseif (strpos($position, 'associate') !== false) {
    $hourly_rate = 5500;
    $rate_label = 'Associate Lecturer (K5,500/hr)';
} else {
    $hourly_rate = 5500;
    $rate_label = 'Default (K5,500/hr)';
}

// Airtime/Bundle rate
$airtime_bundle_rate = 15000;

// Get lecturer's courses with statistics
$courses_query = "
    SELECT 
        vc.*,
        COUNT(DISTINCT ve.student_id) as enrolled_students,
        COUNT(DISTINCT va.assignment_id) as total_assignments,
        SUM(CASE WHEN vs.score IS NOT NULL THEN 1 ELSE 0 END) as marked_assignments,
        COUNT(DISTINCT vwc.content_id) as uploaded_content
    FROM vle_courses vc
    LEFT JOIN vle_enrollments ve ON vc.course_id = ve.course_id
    LEFT JOIN vle_assignments va ON vc.course_id = va.course_id
    LEFT JOIN vle_submissions vs ON va.assignment_id = vs.assignment_id AND vs.score IS NOT NULL
    LEFT JOIN vle_weekly_content vwc ON vc.course_id = vwc.course_id
    WHERE vc.lecturer_id = ?
    GROUP BY vc.course_id
    ORDER BY vc.course_name
";
$stmt = $conn->prepare($courses_query);
$stmt->bind_param("s", $lecturer_id);
$stmt->execute();
$courses = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Handle form submission
$success_message = null;
$error_message = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $selected_courses = $_POST['courses'] ?? [];
    $month = $_POST['month'] ?? '';
    $year = $_POST['year'] ?? '';
    $total_hours = (float)($_POST['total_hours'] ?? 0);
    $additional_notes = trim($_POST['additional_notes'] ?? '');
    $signature_data = $_POST['signature'] ?? '';
    
    if (empty($selected_courses)) {
        $error_message = "Please select at least one course.";
    } elseif (empty($signature_data)) {
        $error_message = "Please provide your signature.";
    } elseif ($total_hours <= 0) {
        $error_message = "Please enter valid total hours worked.";
    } else {
        // Calculate totals
        $total_students = 0;
        $total_assignments_marked = 0;
        $total_content = 0;
        $courses_data = [];
        
        foreach ($selected_courses as $course_id) {
            foreach ($courses as $course) {
                if ($course['course_id'] == $course_id) {
                    $total_students += $course['enrolled_students'];
                    $total_assignments_marked += $course['marked_assignments'];
                    $total_content += $course['uploaded_content'];
                    $courses_data[] = [
                        'course_id' => $course['course_id'],
                        'course_name' => $course['course_name'],
                        'students' => $course['enrolled_students'],
                        'assignments' => $course['marked_assignments'],
                        'content' => $course['uploaded_content']
                    ];
                }
            }
        }
        
        $total_amount = $total_hours * $hourly_rate;
        $courses_json = json_encode($courses_data);
        
        // Save signature
        $signature_filename = null;
        if (!empty($signature_data)) {
            $signature_dir = '../uploads/signatures/';
            if (!is_dir($signature_dir)) {
                mkdir($signature_dir, 0755, true);
            }
            $signature_filename = 'signature_' . $lecturer_id . '_' . time() . '.png';
            $signature_path = $signature_dir . $signature_filename;
            
            // Decode base64 image
            $image_data = explode(',', $signature_data);
            if (count($image_data) > 1) {
                file_put_contents($signature_path, base64_decode($image_data[1]));
            }
        }
        
        // Insert finance request
        $stmt = $conn->prepare("
            INSERT INTO lecturer_finance_requests 
            (lecturer_id, month, year, courses_data, total_students, total_modules, 
             total_assignments_marked, total_content_uploaded, total_hours, hourly_rate, 
             total_amount, signature_path, additional_notes, status, submission_date)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
        ");
        
        $total_modules = count($selected_courses);
        $stmt->bind_param(
            "siisiiidddsss",
            $lecturer_id, $month, $year, $courses_json, $total_students, $total_modules,
            $total_assignments_marked, $total_content, $total_hours, $hourly_rate,
            $total_amount, $signature_filename, $additional_notes
        );
        
        if ($stmt->execute()) {
            $success_message = "Finance request submitted successfully! Request ID: " . $stmt->insert_id;
        } else {
            $error_message = "Failed to submit request: " . $conn->error;
        }
    }
}

// Get previous requests
$previous_requests = [];
$stmt = $conn->prepare("
    SELECT * FROM lecturer_finance_requests 
    WHERE lecturer_id = ? 
    ORDER BY request_date DESC 
    LIMIT 10
");
$stmt->bind_param("s", $lecturer_id);
$stmt->execute();
$previous_requests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$user = getCurrentUser();
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Finance Request - VLE System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <style>
        .signature-pad {
            border: 2px solid #ddd;
            border-radius: 8px;
            cursor: crosshair;
            background: white;
        }
        .stats-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .course-checkbox {
            padding: 15px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            margin-bottom: 10px;
            transition: all 0.3s;
        }
        .course-checkbox:hover {
            border-color: #667eea;
            background: #f8f9fa;
        }
        .course-checkbox input:checked ~ label {
            color: #667eea;
            font-weight: bold;
        }
        .readonly-field {
            background-color: #f8f9fa;
            cursor: not-allowed;
        }
    </style>
</head>
<body class="bg-light">
    <nav class="navbar navbar-expand-lg navbar-dark bg-success">
        <div class="container-fluid">
            <a class="navbar-brand" href="dashboard.php">
                <i class="bi bi-mortarboard"></i> VLE System - Lecturer
            </a>
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="dashboard.php">
                    <i class="bi bi-arrow-left"></i> Back to Dashboard
                </a>
            </div>
        </div>
    </nav>

    <div class="container mt-4 pb-5">
        <div class="row">
            <div class="col-md-12">
                <h2 class="mb-4"><i class="bi bi-cash-coin"></i> Finance Request Submission</h2>

                <?php if ($success_message): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <i class="bi bi-check-circle"></i> <?php echo htmlspecialchars($success_message); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if ($error_message): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <i class="bi bi-exclamation-triangle"></i> <?php echo htmlspecialchars($error_message); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Lecturer Bio Data Card -->
                    <?php if (isset($_GET['msg'])): ?>
                        <div class="alert alert-info alert-dismissible fade show" role="alert">
                            <?php echo htmlspecialchars($_GET['msg']); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    <?php endif; ?>
                <div class="card mb-4">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="bi bi-person-badge"></i> Lecturer Information</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <p><strong>Full Name:</strong> <?php echo htmlspecialchars($lecturer['full_name']); ?></p>
                                <p><strong>Lecturer ID:</strong> <?php echo htmlspecialchars($lecturer_id); ?></p>
                                <p><strong>Email:</strong> <?php echo htmlspecialchars($lecturer['email']); ?></p>
                                <p><strong>Phone:</strong> <?php echo htmlspecialchars($lecturer['phone'] ?? 'N/A'); ?></p>
                            </div>
                            <div class="col-md-6">
                                <p><strong>Qualification:</strong> <?php echo htmlspecialchars($lecturer['qualification'] ?? 'N/A'); ?></p>
                                <p><strong>Department:</strong> <?php echo htmlspecialchars($lecturer['department'] ?? 'N/A'); ?></p>
                                <p><strong>Hourly Rate:</strong> <span class="badge bg-success fs-6">K<?php echo number_format($hourly_rate); ?></span> <span class="text-muted small ms-2"><?php echo $rate_label; ?></span></p>
                                <p><strong>Airtime/Bundle Rate:</strong> <span class="badge bg-info fs-6">K<?php echo number_format($airtime_bundle_rate); ?></span> <span class="text-muted small ms-2">per request</span></p>
                                <p><strong>NRC:</strong> <?php echo htmlspecialchars($lecturer['nrc'] ?? 'N/A'); ?></p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Request Form -->
                <form method="POST" id="financeRequestForm">
                    <div class="card mb-4">
                        <div class="card-header bg-success text-white">
                            <h5 class="mb-0"><i class="bi bi-calendar-month"></i> Request Period</h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <label class="form-label">Month *</label>
                                    <select class="form-select" name="month" required>
                                        <option value="">Select Month</option>
                                        <option value="1">January</option>
                                        <option value="2">February</option>
                                        <option value="3">March</option>
                                        <option value="4">April</option>
                                        <option value="5">May</option>
                                        <option value="6">June</option>
                                        <option value="7">July</option>
                                        <option value="8">August</option>
                                        <option value="9">September</option>
                                        <option value="10">October</option>
                                        <option value="11">November</option>
                                        <option value="12">December</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Year *</label>
                                    <select class="form-select" name="year" required>
                                        <option value="">Select Year</option>
                                        <?php for ($y = date('Y'); $y >= date('Y') - 2; $y--): ?>
                                            <option value="<?php echo $y; ?>"><?php echo $y; ?></option>
                                        <?php endfor; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Select Courses -->
                    <div class="card mb-4">
                        <div class="card-header bg-info text-white">
                            <h5 class="mb-0"><i class="bi bi-book"></i> Select Modules/Courses</h5>
                        </div>
                        <div class="card-body">
                            <?php if (empty($courses)): ?>
                                <div class="alert alert-warning">
                                    You don't have any active courses. Please create a course first.
                                </div>
                            <?php else: ?>
                                <div class="row">
                                    <?php foreach ($courses as $course): ?>
                                        <div class="col-md-6 mb-3">
                                            <div class="course-checkbox">
                                                <input type="checkbox" class="form-check-input me-2" 
                                                       name="courses[]" value="<?php echo $course['course_id']; ?>"
                                                       id="course_<?php echo $course['course_id']; ?>"
                                                       onchange="updateTotals()">
                                                <label class="form-check-label" for="course_<?php echo $course['course_id']; ?>">
                                                    <strong><?php echo htmlspecialchars($course['course_name']); ?></strong><br>
                                                    <small class="text-muted">Code: <?php echo htmlspecialchars($course['course_code']); ?></small>
                                                </label>
                                                <div class="mt-2 small">
                                                    <span class="badge bg-primary">
                                                        <i class="bi bi-people"></i> <?php echo $course['enrolled_students']; ?> Students
                                                    </span>
                                                    <span class="badge bg-success">
                                                        <i class="bi bi-check2-square"></i> <?php echo $course['marked_assignments']; ?> Marked
                                                    </span>
                                                    <span class="badge bg-info">
                                                        <i class="bi bi-file-earmark"></i> <?php echo $course['uploaded_content']; ?> Content
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Work Summary (Auto-calculated, Read-only) -->
                    <div class="card mb-4">
                        <div class="card-header bg-warning">
                            <h5 class="mb-0"><i class="bi bi-bar-chart"></i> Work Summary (Auto-Calculated)</h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Total Modules</label>
                                    <input type="text" class="form-control readonly-field" id="total_modules" readonly value="0">
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Total Students</label>
                                    <input type="text" class="form-control readonly-field" id="total_students" readonly value="0">
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Assignments Marked</label>
                                    <input type="text" class="form-control readonly-field" id="total_marked" readonly value="0">
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Content Uploaded</label>
                                    <input type="text" class="form-control readonly-field" id="total_content" readonly value="0">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Hours and Calculation -->
                    <div class="card mb-4">
                        <div class="card-header bg-secondary text-white">
                            <h5 class="mb-0"><i class="bi bi-clock"></i> Hours Worked & Payment Calculation</h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Total Hours Worked *</label>
                                    <input type="number" class="form-control" name="total_hours" 
                                           id="total_hours" step="0.5" min="0" required 
                                           onchange="calculateTotal()">
                                    <div class="form-text">Enter total hours worked for selected courses</div>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Hourly Rate</label>
                                    <input type="text" class="form-control readonly-field" 
                                           value="K<?php echo number_format($hourly_rate); ?>" readonly>
                                    <div class="form-text">Based on your position</div>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Airtime/Bundle Rate</label>
                                    <input type="text" class="form-control readonly-field" 
                                           value="K<?php echo number_format($airtime_bundle_rate); ?>" readonly>
                                    <div class="form-text">Per request</div>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Total Amount</label>
                                    <input type="text" class="form-control readonly-field bg-success text-white" 
                                           id="total_amount" readonly value="K0.00">
                                    <div class="form-text">Calculated automatically (Hours x Rate + Airtime)</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Additional Notes -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="bi bi-card-text"></i> Additional Notes</h5>
                        </div>
                        <div class="card-body">
                            <textarea class="form-control" name="additional_notes" rows="3" 
                                      placeholder="Any additional information or special circumstances..."></textarea>
                        </div>
                    </div>

                    <!-- Signature Pad -->
                    <div class="card mb-4">
                        <div class="card-header bg-danger text-white">
                            <h5 class="mb-0"><i class="bi bi-pen"></i> Signature *</h5>
                        </div>
                        <div class="card-body">
                            <p class="text-muted">Please sign below to certify that the information provided is accurate:</p>
                            <div class="text-center mb-3">
                                <canvas id="signaturePad" class="signature-pad" width="600" height="200"></canvas>
                            </div>
                            <div class="text-center">
                                <button type="button" class="btn btn-outline-secondary" onclick="clearSignature()">
                                    <i class="bi bi-eraser"></i> Clear Signature
                                </button>
                            </div>
                            <input type="hidden" name="signature" id="signatureData" required>
                            <div class="form-text mt-2">
                                <i class="bi bi-info-circle"></i> By signing, you certify that all information is correct and complete.
                            </div>
                        </div>
                    </div>

                    <!-- Submit Button -->
                    <div class="text-center mb-4">
                        <button type="submit" class="btn btn-primary btn-lg px-5">
                            <i class="bi bi-send"></i> Submit Finance Request
                        </button>
                    </div>
                </form>

                <!-- Previous Requests -->
                <?php if (!empty($previous_requests)): ?>
                    <div class="card">
                        <div class="card-header bg-secondary text-white">
                            <h5 class="mb-0"><i class="bi bi-clock-history"></i> Previous Requests</h5>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Period</th>
                                            <th>Modules</th>
                                            <th>Hours</th>
                                            <th>Amount</th>
                                            <th>Status</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($previous_requests as $req): ?>
                                            <tr>
                                                <td>
                                                    <?php
                                                    if (!empty($req['submission_date'])) {
                                                        // Only format if not null/empty
                                                        echo date('M d, Y', strtotime($req['submission_date']));
                                                    } else {
                                                        echo '<span class="text-muted">N/A</span>';
                                                    }
                                                    ?>
                                                </td>
                                                <td>
                                                    <?php
                                                    if (isset($req['month']) && isset($req['year']) && $req['month'] && $req['year']) {
                                                        echo date('F Y', mktime(0, 0, 0, $req['month'], 1, $req['year']));
                                                    } else {
                                                        echo '<span class="text-muted">N/A</span>';
                                                    }
                                                    ?>
                                                </td>
                                                <td>
                                                    <?php
                                                    if (isset($req['total_modules'])) {
                                                        echo htmlspecialchars($req['total_modules']);
                                                    } else {
                                                        echo '<span class="text-muted">N/A</span>';
                                                    }
                                                    ?>
                                                </td>
                                                <td>
                                                    <?php
                                                    if (isset($req['total_hours'])) {
                                                        echo htmlspecialchars($req['total_hours']) . 'h';
                                                    } else {
                                                        echo '<span class="text-muted">N/A</span>';
                                                    }
                                                    ?>
                                                </td>
                                                <td>
                                                    <strong>
                                                    <?php
                                                    if (isset($req['total_amount']) && $req['total_amount'] !== null && $req['total_amount'] !== '') {
                                                        echo 'K' . number_format((float)$req['total_amount'], 2);
                                                    } else {
                                                        echo '<span class="text-muted">N/A</span>';
                                                    }
                                                    ?>
                                                    </strong>
                                                </td>
                                                <td>
                                                    <?php
                                                    $status_class = [
                                                        'pending' => 'warning',
                                                        'approved' => 'success',
                                                        'rejected' => 'danger',
                                                        'paid' => 'info'
                                                    ];
                                                    $badge_class = $status_class[$req['status']] ?? 'secondary';
                                                    ?>
                                                    <span class="badge bg-<?php echo $badge_class; ?>">
                                                        <?php echo ucfirst($req['status']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                     <?php if (!empty($req['request_id'])): ?>
                                                    <a href="view_finance_request.php?id=<?php echo urlencode($req['request_id']); ?>" 
                                                       class="btn btn-sm btn-outline-primary">
                                                        <i class="bi bi-eye"></i> View
                                                    </a>
                                                    <?php else: ?>
                                                    <span class="btn btn-sm btn-outline-secondary disabled" title="No request ID">
                                                        <i class="bi bi-eye-slash"></i> N/A
                                                    </span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Course data for calculations
        const coursesData = <?php echo json_encode($courses); ?>;
        const hourlyRate = <?php echo $hourly_rate; ?>;

        // Signature Pad
        const canvas = document.getElementById('signaturePad');
        const ctx = canvas.getContext('2d');
        let isDrawing = false;
        let lastX = 0;
        let lastY = 0;

        canvas.addEventListener('mousedown', startDrawing);
        canvas.addEventListener('mousemove', draw);
        canvas.addEventListener('mouseup', stopDrawing);
        canvas.addEventListener('mouseout', stopDrawing);

        // Touch events for mobile
        canvas.addEventListener('touchstart', handleTouch);
        canvas.addEventListener('touchmove', handleTouch);
        canvas.addEventListener('touchend', stopDrawing);

        function startDrawing(e) {
            isDrawing = true;
            [lastX, lastY] = [e.offsetX, e.offsetY];
        }

        function draw(e) {
            if (!isDrawing) return;
            ctx.beginPath();
            ctx.moveTo(lastX, lastY);
            ctx.lineTo(e.offsetX, e.offsetY);
            ctx.strokeStyle = '#000';
            ctx.lineWidth = 2;
            ctx.lineCap = 'round';
            ctx.stroke();
            [lastX, lastY] = [e.offsetX, e.offsetY];
        }

        function stopDrawing() {
            if (isDrawing) {
                isDrawing = false;
                saveSignature();
            }
        }

        function handleTouch(e) {
            e.preventDefault();
            const touch = e.touches[0];
            const rect = canvas.getBoundingClientRect();
            const x = touch.clientX - rect.left;
            const y = touch.clientY - rect.top;

            if (e.type === 'touchstart') {
                isDrawing = true;
                [lastX, lastY] = [x, y];
            } else if (e.type === 'touchmove' && isDrawing) {
                ctx.beginPath();
                ctx.moveTo(lastX, lastY);
                ctx.lineTo(x, y);
                ctx.strokeStyle = '#000';
                ctx.lineWidth = 2;
                ctx.lineCap = 'round';
                ctx.stroke();
                [lastX, lastY] = [x, y];
            }
        }

        function clearSignature() {
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            document.getElementById('signatureData').value = '';
        }

        function saveSignature() {
            const dataURL = canvas.toDataURL('image/png');
            document.getElementById('signatureData').value = dataURL;
        }

        // Update totals when courses are selected
        function updateTotals() {
            const checkboxes = document.querySelectorAll('input[name="courses[]"]:checked');
            let totalStudents = 0;
            let totalMarked = 0;
            let totalContent = 0;

            checkboxes.forEach(checkbox => {
                const courseId = parseInt(checkbox.value);
                const course = coursesData.find(c => c.course_id === courseId);
                if (course) {
                    totalStudents += parseInt(course.enrolled_students);
                    totalMarked += parseInt(course.marked_assignments);
                    totalContent += parseInt(course.uploaded_content);
                }
            });

            document.getElementById('total_modules').value = checkboxes.length;
            document.getElementById('total_students').value = totalStudents;
            document.getElementById('total_marked').value = totalMarked;
            document.getElementById('total_content').value = totalContent;
            
            calculateTotal();
        }

        // Calculate total amount
        function calculateTotal() {
            const hours = parseFloat(document.getElementById('total_hours').value) || 0;
            const total = (hours * hourlyRate) + <?php echo $airtime_bundle_rate; ?>;
            document.getElementById('total_amount').value = 'K' + total.toLocaleString('en-US', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }

        // Form validation
        document.getElementById('financeRequestForm').addEventListener('submit', function(e) {
            const signature = document.getElementById('signatureData').value;
            if (!signature) {
                e.preventDefault();
                alert('Please provide your signature before submitting.');
                return false;
            }

            const checkboxes = document.querySelectorAll('input[name="courses[]"]:checked');
            if (checkboxes.length === 0) {
                e.preventDefault();
                alert('Please select at least one course.');
                return false;
            }
        });
    </script>
</body>
</html>
composer require mpdf/mpdf