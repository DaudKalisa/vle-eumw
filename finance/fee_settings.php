<?php
// finance/fee_settings.php - Manage fee settings (moved from admin)
require_once '../includes/auth.php';
requireLogin();
requireRole(['finance', 'staff']);

$conn = getDbConnection();

$success = '';
$error = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_fees'])) {
    $application_fee = floatval($_POST['application_fee']);
    $registration_fee = floatval($_POST['registration_fee']);
    $tuition_degree = floatval($_POST['tuition_degree']);
    $tuition_professional = floatval($_POST['tuition_professional']);
    $tuition_masters = floatval($_POST['tuition_masters']);
    $tuition_doctorate = floatval($_POST['tuition_doctorate']);
    $supplementary_exam = floatval($_POST['supplementary_exam_fee']);
    $deferred_exam = floatval($_POST['deferred_exam_fee']);
    $dissertation_fee = floatval($_POST['dissertation_fee'] ?? 250000);
    $lecturer_hourly_rate = floatval($_POST['lecturer_hourly_rate'] ?? 9500);
    $lecturer_airtime_rate = floatval($_POST['lecturer_airtime_rate'] ?? 15000);
    $new_student_reg_fee = floatval($_POST['new_student_reg_fee'] ?? 39500);
    $continuing_reg_fee = floatval($_POST['continuing_reg_fee'] ?? 35000);
    
    // Ensure new columns exist
    $conn->query("ALTER TABLE fee_settings ADD COLUMN IF NOT EXISTS dissertation_fee DECIMAL(12,2) DEFAULT 250000.00");
    $conn->query("ALTER TABLE fee_settings ADD COLUMN IF NOT EXISTS lecturer_hourly_rate DECIMAL(10,2) DEFAULT 9500.00");
    $conn->query("ALTER TABLE fee_settings ADD COLUMN IF NOT EXISTS lecturer_airtime_rate DECIMAL(10,2) DEFAULT 15000.00");
    $conn->query("ALTER TABLE fee_settings ADD COLUMN IF NOT EXISTS new_student_reg_fee DECIMAL(12,2) DEFAULT 39500.00");
    $conn->query("ALTER TABLE fee_settings ADD COLUMN IF NOT EXISTS continuing_reg_fee DECIMAL(12,2) DEFAULT 35000.00");
    
    $stmt = $conn->prepare("UPDATE fee_settings SET 
        application_fee = ?,
        registration_fee = ?,
        new_student_reg_fee = ?,
        continuing_reg_fee = ?,
        tuition_degree = ?,
        tuition_professional = ?,
        tuition_masters = ?,
        tuition_doctorate = ?,
        supplementary_exam_fee = ?,
        deferred_exam_fee = ?,
        dissertation_fee = ?,
        lecturer_hourly_rate = ?,
        lecturer_airtime_rate = ?
        WHERE id = 1");
    
    $stmt->bind_param("ddddddddddddd", $application_fee, $registration_fee, $new_student_reg_fee, $continuing_reg_fee, $tuition_degree, $tuition_professional, $tuition_masters, $tuition_doctorate, $supplementary_exam, $deferred_exam, $dissertation_fee, $lecturer_hourly_rate, $lecturer_airtime_rate);
    
    if ($stmt->execute()) {
        $success = "Fee settings updated successfully!";
    } else {
        $error = "Failed to update fee settings: " . $stmt->error;
    }
}

// Get current fees - ensure all columns exist
$conn->query("ALTER TABLE fee_settings ADD COLUMN IF NOT EXISTS dissertation_fee DECIMAL(12,2) DEFAULT 250000.00");
$conn->query("ALTER TABLE fee_settings ADD COLUMN IF NOT EXISTS lecturer_hourly_rate DECIMAL(10,2) DEFAULT 9500.00");
$conn->query("ALTER TABLE fee_settings ADD COLUMN IF NOT EXISTS lecturer_airtime_rate DECIMAL(10,2) DEFAULT 15000.00");
$conn->query("ALTER TABLE fee_settings ADD COLUMN IF NOT EXISTS new_student_reg_fee DECIMAL(12,2) DEFAULT 39500.00");
$conn->query("ALTER TABLE fee_settings ADD COLUMN IF NOT EXISTS continuing_reg_fee DECIMAL(12,2) DEFAULT 35000.00");
$fees = $conn->query("SELECT * FROM fee_settings LIMIT 1")->fetch_assoc();
if (!$fees) {
    $fees = [
        'application_fee' => 5500,
        'registration_fee' => 39500,
        'new_student_reg_fee' => 39500,
        'continuing_reg_fee' => 35000,
        'tuition_degree' => 500000,
        'tuition_professional' => 200000,
        'tuition_masters' => 1100000,
        'tuition_doctorate' => 2200000,
        'supplementary_exam_fee' => 50000,
        'deferred_exam_fee' => 50000,
        'dissertation_fee' => 250000,
        'lecturer_hourly_rate' => 9500,
        'lecturer_airtime_rate' => 15000
    ];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fee Settings - Finance</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
</head>
<body class="bg-light">
    <?php include 'header_nav.php'; ?>

    <div class="container-fluid px-3 px-lg-4 mt-3 mt-lg-4">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header bg-success text-white">
                        <i class="bi bi-gear"></i> Fee Settings
                    </div>
                    <div class="card-body">
                        <?php if ($success): ?>
                            <div class="alert alert-success"> <?php echo $success; ?> </div>
                        <?php endif; ?>
                        <?php if ($error): ?>
                            <div class="alert alert-danger"> <?php echo $error; ?> </div>
                        <?php endif; ?>
                        <form method="post">
                            <!-- Basic Fees -->
                            <h5 class="border-bottom pb-2 mb-3"><i class="bi bi-credit-card"></i> Basic Fees</h5>
                            <div class="row mb-3">
                                <div class="col">
                                    <label>Application Fee (K)</label>
                                    <input type="number" step="0.01" name="application_fee" class="form-control" value="<?php echo htmlspecialchars($fees['application_fee']); ?>" required>
                                </div>
                                <div class="col">
                                    <label>Registration Fee (K)</label>
                                    <input type="number" step="0.01" name="registration_fee" class="form-control" value="<?php echo htmlspecialchars($fees['registration_fee']); ?>" required>
                                    <small class="text-muted">Default/fallback registration fee</small>
                                </div>
                            </div>

                            <!-- Registration Fees by Student Type -->
                            <h5 class="border-bottom pb-2 mb-3"><i class="bi bi-people"></i> Registration Fees by Student Type</h5>
                            <div class="row mb-3">
                                <div class="col">
                                    <label>New Student Registration Fee (K)</label>
                                    <input type="number" step="0.01" name="new_student_reg_fee" class="form-control" value="<?php echo htmlspecialchars($fees['new_student_reg_fee'] ?? 39500); ?>" required>
                                    <small class="text-muted">For first-time students (Year 1 Semester 1)</small>
                                </div>
                                <div class="col">
                                    <label>Continuing Student Registration Fee (K)</label>
                                    <input type="number" step="0.01" name="continuing_reg_fee" class="form-control" value="<?php echo htmlspecialchars($fees['continuing_reg_fee'] ?? 35000); ?>" required>
                                    <small class="text-muted">For returning students</small>
                                </div>
                            </div>

                            <!-- Tuition Fees -->
                            <h5 class="border-bottom pb-2 mb-3"><i class="bi bi-book"></i> Tuition Fees by Program Type</h5>
                            <div class="row mb-3">
                                <div class="col">
                                    <label>Tuition (Degree) (K)</label>
                                    <input type="number" step="0.01" name="tuition_degree" class="form-control" value="<?php echo htmlspecialchars($fees['tuition_degree']); ?>" required>
                                </div>
                                <div class="col">
                                    <label>Tuition (Professional) (K)</label>
                                    <input type="number" step="0.01" name="tuition_professional" class="form-control" value="<?php echo htmlspecialchars($fees['tuition_professional']); ?>" required>
                                </div>
                            </div>
                            <div class="row mb-3">
                                <div class="col">
                                    <label>Tuition (Masters) (K)</label>
                                    <input type="number" step="0.01" name="tuition_masters" class="form-control" value="<?php echo htmlspecialchars($fees['tuition_masters']); ?>" required>
                                </div>
                                <div class="col">
                                    <label>Tuition (Doctorate) (K)</label>
                                    <input type="number" step="0.01" name="tuition_doctorate" class="form-control" value="<?php echo htmlspecialchars($fees['tuition_doctorate']); ?>" required>
                                </div>
                            </div>

                            <!-- Examination Fees -->
                            <h5 class="border-bottom pb-2 mb-3"><i class="bi bi-pencil-square"></i> Examination Fees</h5>
                            <div class="row mb-3">
                                <div class="col">
                                    <label>Supplementary Exam Fee (K)</label>
                                    <input type="number" step="0.01" name="supplementary_exam_fee" class="form-control" value="<?php echo htmlspecialchars($fees['supplementary_exam_fee']); ?>" required>
                                </div>
                                <div class="col">
                                    <label>Deferred Exam Fee (K)</label>
                                    <input type="number" step="0.01" name="deferred_exam_fee" class="form-control" value="<?php echo htmlspecialchars($fees['deferred_exam_fee']); ?>" required>
                                </div>
                            </div>

                            <!-- Dissertation Fee -->
                            <h5 class="border-bottom pb-2 mb-3"><i class="bi bi-mortarboard"></i> Dissertation Fee</h5>
                            <div class="row mb-3">
                                <div class="col-6">
                                    <label>Dissertation Fee (K) <small class="text-muted">(3 equal installments)</small></label>
                                    <input type="number" step="0.01" name="dissertation_fee" class="form-control" value="<?php echo htmlspecialchars($fees['dissertation_fee'] ?? 250000); ?>" required>
                                    <small class="text-muted">Separate from tuition. Charged to dissertation students only.</small>
                                </div>
                            </div>

                            <!-- Lecturer Claim Rates -->
                            <h5 class="border-bottom pb-2 mb-3"><i class="bi bi-person-badge"></i> Lecturer Claim Rates</h5>
                            <div class="row mb-3">
                                <div class="col">
                                    <label>Lecturer Hourly Rate (K)</label>
                                    <input type="number" step="0.01" name="lecturer_hourly_rate" class="form-control" value="<?php echo htmlspecialchars($fees['lecturer_hourly_rate'] ?? 9500); ?>" required>
                                    <small class="text-muted">Default rate per hour for lecturer claims</small>
                                </div>
                                <div class="col">
                                    <label>Lecturer Airtime/Bundle Rate (K)</label>
                                    <input type="number" step="0.01" name="lecturer_airtime_rate" class="form-control" value="<?php echo htmlspecialchars($fees['lecturer_airtime_rate'] ?? 15000); ?>" required>
                                    <small class="text-muted">Fixed airtime/data allowance per claim</small>
                                </div>
                            </div>

                            <button type="submit" name="update_fees" class="btn btn-success"><i class="bi bi-save"></i> Update Fees</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
