<?php
// request_finance.php - Lecturer Finance Request System
require_once '../includes/auth.php';
requireLogin();
requireRole(['lecturer']);

$conn = getDbConnection();
$lecturer_id = getRelatedIdForRole('lecturer');

// Get lecturer profile data
$stmt = $conn->prepare("SELECT * FROM lecturers WHERE lecturer_id = ?");
$stmt->bind_param("s", $lecturer_id);
$stmt->execute();
$lecturer = $stmt->get_result()->fetch_assoc();

if (!$lecturer) {
    $_SESSION['vle_error'] = 'Lecturer profile not found. Please contact administrator to link your account.';
    header('Location: ../dashboard.php');
    exit();
}

// Get rates from fee_settings (configurable by finance)
$conn->query("ALTER TABLE fee_settings ADD COLUMN IF NOT EXISTS lecturer_hourly_rate DECIMAL(10,2) DEFAULT 9500.00");
$conn->query("ALTER TABLE fee_settings ADD COLUMN IF NOT EXISTS lecturer_airtime_rate DECIMAL(10,2) DEFAULT 15000.00");
$conn->query("ALTER TABLE lecturer_finance_requests ADD COLUMN IF NOT EXISTS bank_name VARCHAR(100) DEFAULT NULL");
$conn->query("ALTER TABLE lecturer_finance_requests ADD COLUMN IF NOT EXISTS account_number VARCHAR(50) DEFAULT NULL");
$fee_rates = $conn->query("SELECT lecturer_hourly_rate, lecturer_airtime_rate FROM fee_settings LIMIT 1")->fetch_assoc();
$db_hourly_rate = (float)($fee_rates['lecturer_hourly_rate'] ?? 9500);
$db_airtime_rate = (float)($fee_rates['lecturer_airtime_rate'] ?? 15000);

// Determine hourly rate based on position (using DB rate as base)
$position = strtolower($lecturer['position'] ?? '');
$hourly_rate = $db_hourly_rate;
if (strpos($position, 'senior lecturer') !== false) {
    $rate_label = 'Senior Lecturer (MKW' . number_format($hourly_rate) . '/hr)';
} elseif (strpos($position, 'lecturer') !== false && strpos($position, 'associate') === false) {
    $rate_label = 'Lecturer (MKW' . number_format($hourly_rate) . '/hr)';
} elseif (strpos($position, 'associate') !== false) {
    $rate_label = 'Associate Lecturer (MKW' . number_format($hourly_rate) . '/hr)';
} else {
    $rate_label = 'Default (MKW' . number_format($hourly_rate) . '/hr)';
}

// Airtime/Bundle rate from DB
$airtime_bundle_rate = $db_airtime_rate;

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
    $additional_notes = trim($_POST['additional_notes'] ?? '');
    $signature_data = $_POST['signature'] ?? '';
    $bank_name = trim($_POST['bank_name'] ?? '');
    $account_number = trim($_POST['account_number'] ?? '');
    $course_hours = $_POST['course_hours'] ?? [];
    
    if (empty($selected_courses)) {
        $error_message = "Please select at least one course.";
    } elseif (empty($signature_data)) {
        $error_message = "Please provide your signature.";
    } else {
        // Calculate totals
        $total_students = 0;
        $total_assignments_marked = 0;
        $total_content = 0;
        $total_hours = 0;
        $courses_data = [];
        
        foreach ($selected_courses as $course_id) {
            foreach ($courses as $course) {
                if ($course['course_id'] == $course_id) {
                    $ch = (float)($course_hours[$course_id] ?? 0);
                    $total_students += $course['enrolled_students'];
                    $total_assignments_marked += $course['marked_assignments'];
                    $total_content += $course['uploaded_content'];
                    $total_hours += $ch;
                    $courses_data[] = [
                        'course_id' => $course['course_id'],
                        'course_name' => $course['course_name'],
                        'students' => $course['enrolled_students'],
                        'assignments' => $course['marked_assignments'],
                        'content' => $course['uploaded_content'],
                        'hours' => $ch
                    ];
                }
            }
        }
        
        if ($total_hours <= 0) {
            $error_message = "Please enter valid hours for at least one course.";
        } else {
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
             total_amount, signature_path, additional_notes, bank_name, account_number, status, submission_date)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
        ");
        
        $total_modules = count($selected_courses);
        $stmt->bind_param(
            "siisiiidddsssss",
            $lecturer_id, $month, $year, $courses_json, $total_students, $total_modules,
            $total_assignments_marked, $total_content, $total_hours, $hourly_rate,
            $total_amount, $signature_filename, $additional_notes, $bank_name, $account_number
        );
        
        if ($stmt->execute()) {
            $success_message = "Finance request submitted successfully! Request ID: " . $stmt->insert_id;
        } else {
            $error_message = "Failed to submit request: " . $conn->error;
        }
        } // end total_hours check
    }
}

// Get previous requests
$previous_requests = [];
$stmt = $conn->prepare("
    SELECT * FROM lecturer_finance_requests 
    WHERE lecturer_id = ? 
    ORDER BY submission_date DESC 
    LIMIT 10
");
$stmt->bind_param("s", $lecturer_id);
$stmt->execute();
$previous_requests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get already claimed months for this year (exclude rejected requests)
$claimed_months = [];
$current_year = date('Y');
$stmt = $conn->prepare("
    SELECT DISTINCT month FROM lecturer_finance_requests 
    WHERE lecturer_id = ? AND year = ? AND status NOT IN ('rejected')
");
$stmt->bind_param("si", $lecturer_id, $current_year);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $claimed_months[] = (int)$row['month'];
}

$user = getCurrentUser();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Finance Request - VLE System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
    <style>
        .signature-pad {
            border: 2px solid #ddd;
            border-radius: var(--vle-radius);
            cursor: crosshair;
            background: white;
        }
        .stats-card {
            background: var(--vle-gradient-primary);
            color: white;
            border-radius: var(--vle-radius);
            padding: 20px;
            margin-bottom: 20px;
        }
        .course-checkbox {
            padding: 15px;
            border: 2px solid #e0e0e0;
            border-radius: var(--vle-radius);
            margin-bottom: 10px;
            transition: all 0.3s;
        }
        .course-checkbox:hover {
            border-color: var(--vle-accent);
            background: #f8f9fa;
        }
        .course-checkbox input:checked ~ label {
            color: var(--vle-accent);
            font-weight: bold;
        }
        .readonly-field {
            background-color: #f8f9fa;
            cursor: not-allowed;
        }
    </style>
</head>
<body>
    <?php 
    $currentPage = 'request_finance';
    $pageTitle = 'Finance Request';
    include 'header_nav.php'; 
    ?>

    <div class="vle-content">
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
            <h2 class="vle-page-title"><i class="bi bi-cash-coin me-2"></i>Finance Request Submission</h2>
            <a href="dashboard.php" class="btn btn-vle-secondary"><i class="bi bi-arrow-left me-1"></i> Back to Dashboard</a>
        </div>

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
                                <p><strong>Hourly Rate:</strong> <span class="badge bg-success fs-6">MKW<?php echo number_format($hourly_rate); ?></span> <span class="text-muted small ms-2"><?php echo $rate_label; ?></span></p>
                                <p><strong>Airtime/Bundle Rate:</strong> <span class="badge bg-info fs-6">MKW<?php echo number_format($airtime_bundle_rate); ?></span> <span class="text-muted small ms-2">per request</span></p>
                                <p><strong>NRC:</strong> <?php echo htmlspecialchars($lecturer['nrc'] ?? 'N/A'); ?></p>
                            </div>
                        </div>
                        <hr>
                        <h6 class="mb-3"><i class="bi bi-bank me-2"></i>Banking Details <small class="text-muted">(for payment processing)</small></h6>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Bank Name *</label>
                                <select class="form-select" name="bank_name" id="bank_name" required>
                                    <option value="">Select Bank</option>
                                    <?php
                                    $banks = ['National Bank of Malawi', 'FDH Bank', 'Standard Bank', 'NBS Bank', 'Ecobank', 'CDH Investment Bank', 'First Capital Bank', 'Opportunity Bank', 'MyBucks Bank', 'Airtel Money', 'TNM Mpamba', 'Other'];
                                    foreach ($banks as $bank):
                                    ?>
                                    <option value="<?php echo $bank; ?>" <?php echo ($lecturer['bank_name'] ?? '') === $bank ? 'selected' : ''; ?>><?php echo $bank; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Account Number *</label>
                                <input type="text" class="form-control" name="account_number" id="account_number" 
                                       value="<?php echo htmlspecialchars($lecturer['account_number'] ?? ''); ?>" 
                                       placeholder="Enter account number" required>
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
                                        <option value="1" <?php echo in_array(1, $claimed_months) ? 'disabled title="Already claimed"' : ''; ?>>January</option>
                                        <option value="2" <?php echo in_array(2, $claimed_months) ? 'disabled title="Already claimed"' : ''; ?>>February</option>
                                        <option value="3" <?php echo in_array(3, $claimed_months) ? 'disabled title="Already claimed"' : ''; ?>>March</option>
                                        <option value="4" <?php echo in_array(4, $claimed_months) ? 'disabled title="Already claimed"' : ''; ?>>April</option>
                                        <option value="5" <?php echo in_array(5, $claimed_months) ? 'disabled title="Already claimed"' : ''; ?>>May</option>
                                        <option value="6" <?php echo in_array(6, $claimed_months) ? 'disabled title="Already claimed"' : ''; ?>>June</option>
                                        <option value="7" <?php echo in_array(7, $claimed_months) ? 'disabled title="Already claimed"' : ''; ?>>July</option>
                                        <option value="8" <?php echo in_array(8, $claimed_months) ? 'disabled title="Already claimed"' : ''; ?>>August</option>
                                        <option value="9" <?php echo in_array(9, $claimed_months) ? 'disabled title="Already claimed"' : ''; ?>>September</option>
                                        <option value="10" <?php echo in_array(10, $claimed_months) ? 'disabled title="Already claimed"' : ''; ?>>October</option>
                                        <option value="11" <?php echo in_array(11, $claimed_months) ? 'disabled title="Already claimed"' : ''; ?>>November</option>
                                        <option value="12" <?php echo in_array(12, $claimed_months) ? 'disabled title="Already claimed"' : ''; ?>>December</option>
                                    </select>
                                    <small class="text-muted d-block mt-2">Disabled months have already been claimed.</small>
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
                            <h5 class="mb-0"><i class="bi bi-book"></i> Select Courses</h5>
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
                                                <div class="mt-2 course-hours-row" id="hours_row_<?php echo $course['course_id']; ?>" style="display:none">
                                                    <label class="form-label small fw-bold mb-1">Hours for this module:</label>
                                                    <input type="number" class="form-control form-control-sm course-hours-input" 
                                                           name="course_hours[<?php echo $course['course_id']; ?>]" 
                                                           data-course-id="<?php echo $course['course_id']; ?>"
                                                           step="0.5" min="0" placeholder="Enter hours" 
                                                           onchange="updateTotalHours()" oninput="updateTotalHours()">
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
                                    <label class="form-label">Total Courses</label>
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
                                    <label class="form-label">Total Hours Worked</label>
                                    <input type="number" class="form-control readonly-field" name="total_hours" 
                                           id="total_hours" step="0.5" min="0" readonly value="0">
                                    <div class="form-text">Auto-calculated from per-course hours</div>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Hourly Rate</label>
                                    <input type="text" class="form-control readonly-field" 
                                           value="MKW<?php echo number_format($hourly_rate); ?>" readonly>
                                    <div class="form-text">Based on your position</div>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Airtime/Bundle Rate</label>
                                    <input type="text" class="form-control readonly-field" 
                                           value="MKW<?php echo number_format($airtime_bundle_rate); ?>" readonly>
                                    <div class="form-text">Per request</div>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Total Amount</label>
                                    <input type="text" class="form-control readonly-field bg-success text-white" 
                                           id="total_amount" readonly value="MKW0.00">
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
                                            <th>Courses</th>
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
                                                        echo 'MKW' . number_format((float)$req['total_amount'], 2);
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
                                                    <button type="button" class="btn btn-sm btn-outline-primary me-1" onclick="previewClaim(<?php echo (int)$req['request_id']; ?>)" title="Preview Claim">
                                                        <i class="bi bi-eye"></i>
                                                    </button>
                                                    <?php endif; ?>
                                                     <?php if (!empty($req['request_id']) && $req['status'] === 'paid'): ?>
                                                    <a href="../finance/print_lecturer_payment.php?id=<?php echo urlencode($req['request_id']); ?>" 
                                                       class="btn btn-sm btn-outline-success" target="_blank" title="View/Print Payment Confirmation">
                                                        <i class="bi bi-printer"></i> Print
                                                    </a>
                                                    <?php elseif (!empty($req['request_id']) && $req['status'] === 'pending'): ?>
                                                    <span class="badge bg-warning text-dark" title="Awaiting approval">
                                                        <i class="bi bi-hourglass-split"></i> Pending
                                                    </span>
                                                    <?php elseif (!empty($req['request_id']) && $req['status'] === 'approved'): ?>
                                                    <span class="badge bg-success" title="Approved, awaiting payment">
                                                        <i class="bi bi-check-circle"></i> Approved
                                                    </span>
                                                    <?php elseif (!empty($req['request_id']) && $req['status'] === 'rejected'): ?>
                                                    <span class="badge bg-danger" title="Request rejected">
                                                        <i class="bi bi-x-circle"></i> Rejected
                                                    </span>
                                                    <?php else: ?>
                                                    <span class="badge bg-secondary" title="No request ID">
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

    <!-- Preview Claim Modal -->
    <div class="modal fade" id="previewClaimModal" tabindex="-1" aria-labelledby="previewClaimModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="previewClaimModalLabel"><i class="bi bi-eye me-2"></i>Claim Preview</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="previewClaimContent">
                    <div class="text-center py-4">
                        <div class="spinner-border text-primary" role="status"></div>
                        <p class="mt-2">Loading claim details...</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
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
            const checkboxes = document.querySelectorAll('input[name="courses[]"]');
            let totalStudents = 0;
            let totalMarked = 0;
            let totalContent = 0;
            let checkedCount = 0;

            checkboxes.forEach(checkbox => {
                const courseId = parseInt(checkbox.value);
                const hoursRow = document.getElementById('hours_row_' + courseId);
                if (checkbox.checked) {
                    checkedCount++;
                    if (hoursRow) hoursRow.style.display = 'block';
                    const course = coursesData.find(c => c.course_id === courseId);
                    if (course) {
                        totalStudents += parseInt(course.enrolled_students);
                        totalMarked += parseInt(course.marked_assignments);
                        totalContent += parseInt(course.uploaded_content);
                    }
                } else {
                    if (hoursRow) {
                        hoursRow.style.display = 'none';
                        const input = hoursRow.querySelector('input');
                        if (input) input.value = '';
                    }
                }
            });

            document.getElementById('total_modules').value = checkedCount;
            document.getElementById('total_students').value = totalStudents;
            document.getElementById('total_marked').value = totalMarked;
            document.getElementById('total_content').value = totalContent;
            
            updateTotalHours();
        }

        // Sum per-course hours and recalculate
        function updateTotalHours() {
            let totalHours = 0;
            document.querySelectorAll('.course-hours-input').forEach(input => {
                if (input.closest('.course-hours-row').style.display !== 'none') {
                    totalHours += parseFloat(input.value) || 0;
                }
            });
            document.getElementById('total_hours').value = totalHours;
            calculateTotal();
        }

        // Calculate total amount
        function calculateTotal() {
            const hours = parseFloat(document.getElementById('total_hours').value) || 0;
            const total = (hours * hourlyRate) + <?php echo $airtime_bundle_rate; ?>;
            document.getElementById('total_amount').value = 'MKW' + total.toLocaleString('en-US', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }

        // Preview Claim
        function previewClaim(requestId) {
            var modal = new bootstrap.Modal(document.getElementById('previewClaimModal'));
            document.getElementById('previewClaimContent').innerHTML = '<div class="text-center py-4"><div class="spinner-border text-primary" role="status"></div><p class="mt-2">Loading claim details...</p></div>';
            modal.show();
            fetch('get_claim_details.php?id=' + requestId)
                .then(function(r) { return r.text(); })
                .then(function(html) {
                    document.getElementById('previewClaimContent').innerHTML = html;
                })
                .catch(function() {
                    document.getElementById('previewClaimContent').innerHTML = '<div class="alert alert-danger">Failed to load claim details.</div>';
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