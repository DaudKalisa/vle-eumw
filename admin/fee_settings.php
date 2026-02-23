<?php
// admin/fee_settings.php - Manage fee settings
require_once '../includes/auth.php';
requireLogin();
requireRole(['staff', 'finance']);

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
    
    $stmt = $conn->prepare("UPDATE fee_settings SET 
        application_fee = ?,
        registration_fee = ?,
        tuition_degree = ?,
        tuition_professional = ?,
        tuition_masters = ?,
        tuition_doctorate = ?,
        supplementary_exam_fee = ?,
        deferred_exam_fee = ?
        WHERE id = 1");
    
    $stmt->bind_param("dddddddd", $application_fee, $registration_fee, $tuition_degree, $tuition_professional, $tuition_masters, $tuition_doctorate, $supplementary_exam, $deferred_exam);
    
    if ($stmt->execute()) {
        $success = "Fee settings updated successfully!";
    } else {
        $error = "Failed to update fee settings: " . $stmt->error;
    }
}

// Get current fees
$fees = $conn->query("SELECT * FROM fee_settings LIMIT 1")->fetch_assoc();
if (!$fees) {
    $fees = [
        'application_fee' => 5500,
        'registration_fee' => 39500,
        'tuition_degree' => 500000,
        'tuition_professional' => 200000,
        'tuition_masters' => 1100000,
        'tuition_doctorate' => 2200000,
        'supplementary_exam_fee' => 50000,
        'deferred_exam_fee' => 50000
    ];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fee Settings - Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container-fluid">
            <a class="navbar-brand" href="dashboard.php">
                <i class="bi bi-cash-stack"></i> Fee Management
            </a>
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="dashboard.php"><i class="bi bi-arrow-left"></i> Back to Dashboard</a>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <div class="row">
            <div class="col-md-8 offset-md-2">
                <div class="card shadow">
                    <div class="card-header bg-success text-white">
                        <h4 class="mb-0"><i class="bi bi-currency-dollar"></i> Fee Settings</h4>
                    </div>
                    <div class="card-body">
                        <?php if ($success): ?>
                            <div class="alert alert-success alert-dismissible fade show">
                                <i class="bi bi-check-circle"></i> <?php echo $success; ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($error): ?>
                            <div class="alert alert-danger alert-dismissible fade show">
                                <i class="bi bi-exclamation-circle"></i> <?php echo $error; ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>

                        <form method="POST">
                            <!-- Basic Fees -->
                            <div class="mb-4">
                                <h5 class="border-bottom pb-2"><i class="bi bi-credit-card"></i> Basic Fees</h5>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Application Fee (K) <span class="text-danger">*</span></label>
                                        <input type="number" class="form-control" name="application_fee" value="<?php echo $fees['application_fee']; ?>" step="0.01" required>
                                        <small class="text-muted">Must be paid before admission</small>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Registration Fee (K) <span class="text-danger">*</span></label>
                                        <input type="number" class="form-control" name="registration_fee" value="<?php echo $fees['registration_fee']; ?>" step="0.01" required>
                                        <small class="text-muted">Paid after admission</small>
                                    </div>
                                </div>
                            </div>

                            <!-- Tuition Fees -->
                            <div class="mb-4">
                                <h5 class="border-bottom pb-2"><i class="bi bi-book"></i> Tuition Fees by Program Type</h5>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Degree Programs (K) <span class="text-danger">*</span></label>
                                        <input type="number" class="form-control" name="tuition_degree" value="<?php echo $fees['tuition_degree']; ?>" step="0.01" required>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Professional Courses (K) <span class="text-danger">*</span></label>
                                        <input type="number" class="form-control" name="tuition_professional" value="<?php echo $fees['tuition_professional']; ?>" step="0.01" required>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Masters Programs (K) <span class="text-danger">*</span></label>
                                        <input type="number" class="form-control" name="tuition_masters" value="<?php echo $fees['tuition_masters']; ?>" step="0.01" required>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Doctorate Programs (K) <span class="text-danger">*</span></label>
                                        <input type="number" class="form-control" name="tuition_doctorate" value="<?php echo $fees['tuition_doctorate']; ?>" step="0.01" required>
                                    </div>
                                </div>
                            </div>

                            <!-- Exam Fees -->
                            <div class="mb-4">
                                <h5 class="border-bottom pb-2"><i class="bi bi-pencil-square"></i> Examination Fees</h5>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Supplementary Exam Fee (K) <span class="text-danger">*</span></label>
                                        <input type="number" class="form-control" name="supplementary_exam_fee" value="<?php echo $fees['supplementary_exam_fee']; ?>" step="0.01" required>
                                        <small class="text-muted">Per supplementary exam</small>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Deferred Exam Fee (K) <span class="text-danger">*</span></label>
                                        <input type="number" class="form-control" name="deferred_exam_fee" value="<?php echo $fees['deferred_exam_fee']; ?>" step="0.01" required>
                                        <small class="text-muted">Per deferred exam</small>
                                    </div>
                                </div>
                            </div>

                            <!-- Fee Summary -->
                            <div class="alert alert-info">
                                <h6><i class="bi bi-info-circle"></i> Total Fees Summary:</h6>
                                <ul class="mb-0">
                                    <li><strong>Degree:</strong> K<?php echo number_format($fees['application_fee'] + $fees['registration_fee'] + $fees['tuition_degree']); ?> (App + Reg + Tuition)</li>
                                    <li><strong>Professional:</strong> K<?php echo number_format($fees['application_fee'] + $fees['registration_fee'] + $fees['tuition_professional']); ?></li>
                                    <li><strong>Masters:</strong> K<?php echo number_format($fees['application_fee'] + $fees['registration_fee'] + $fees['tuition_masters']); ?></li>
                                    <li><strong>Doctorate:</strong> K<?php echo number_format($fees['application_fee'] + $fees['registration_fee'] + $fees['tuition_doctorate']); ?></li>
                                </ul>
                            </div>

                            <div class="text-end">
                                <button type="submit" name="update_fees" class="btn btn-success btn-lg">
                                    <i class="bi bi-save"></i> Update Fee Settings
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
