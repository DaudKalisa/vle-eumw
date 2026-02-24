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
                            <div class="row mb-3">
                                <div class="col">
                                    <label>Application Fee</label>
                                    <input type="number" step="0.01" name="application_fee" class="form-control" value="<?php echo htmlspecialchars($fees['application_fee']); ?>" required>
                                </div>
                                <div class="col">
                                    <label>Registration Fee</label>
                                    <input type="number" step="0.01" name="registration_fee" class="form-control" value="<?php echo htmlspecialchars($fees['registration_fee']); ?>" required>
                                </div>
                            </div>
                            <div class="row mb-3">
                                <div class="col">
                                    <label>Tuition (Degree)</label>
                                    <input type="number" step="0.01" name="tuition_degree" class="form-control" value="<?php echo htmlspecialchars($fees['tuition_degree']); ?>" required>
                                </div>
                                <div class="col">
                                    <label>Tuition (Professional)</label>
                                    <input type="number" step="0.01" name="tuition_professional" class="form-control" value="<?php echo htmlspecialchars($fees['tuition_professional']); ?>" required>
                                </div>
                            </div>
                            <div class="row mb-3">
                                <div class="col">
                                    <label>Tuition (Masters)</label>
                                    <input type="number" step="0.01" name="tuition_masters" class="form-control" value="<?php echo htmlspecialchars($fees['tuition_masters']); ?>" required>
                                </div>
                                <div class="col">
                                    <label>Tuition (Doctorate)</label>
                                    <input type="number" step="0.01" name="tuition_doctorate" class="form-control" value="<?php echo htmlspecialchars($fees['tuition_doctorate']); ?>" required>
                                </div>
                            </div>
                            <div class="row mb-3">
                                <div class="col">
                                    <label>Supplementary Exam Fee</label>
                                    <input type="number" step="0.01" name="supplementary_exam_fee" class="form-control" value="<?php echo htmlspecialchars($fees['supplementary_exam_fee']); ?>" required>
                                </div>
                                <div class="col">
                                    <label>Deferred Exam Fee</label>
                                    <input type="number" step="0.01" name="deferred_exam_fee" class="form-control" value="<?php echo htmlspecialchars($fees['deferred_exam_fee']); ?>" required>
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
