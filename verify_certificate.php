<?php
/**
 * Public Certificate Verification Page
 * Allows anyone with the direct link to verify an exam clearance certificate
 * No login required - accessible via QR code on printed certificate
 */
require_once 'includes/config.php';

$conn = getDbConnection();
$cert_number = trim($_GET['cert'] ?? '');
$student = null;
$error = '';

if (!empty($cert_number)) {
    // Sanitize: only allow alphanumeric, dashes, slashes
    $cert_number = preg_replace('/[^a-zA-Z0-9\-\/]/', '', $cert_number);
    
    $stmt = $conn->prepare("SELECT ecs.student_id, ecs.full_name, ecs.program, ecs.program_type, ecs.department, ecs.campus, ecs.year_of_study, ecs.semester, ecs.certificate_number, ecs.clearance_type, ecs.status, ecs.cleared_at, ecs.invoiced_amount, ecs.balance FROM exam_clearance_students ecs WHERE ecs.certificate_number = ? AND ecs.status = 'cleared'");
    $stmt->bind_param("s", $cert_number);
    $stmt->execute();
    $student = $stmt->get_result()->fetch_assoc();
    
    if (!$student) {
        $error = 'Certificate not found or not valid. Please check the certificate number and try again.';
    }
} else {
    $error = 'No certificate number provided.';
}

// University settings
$university_name = "Exploits University";
$university_email = "finance@exploitsuniversity.edu";
$university_website = "www.exploitsuniversity.edu";

$settings_query = $conn->query("SELECT * FROM university_settings LIMIT 1");
if ($settings_query && $settings_query->num_rows > 0) {
    $settings = $settings_query->fetch_assoc();
    $university_name = $settings['university_name'] ?? $university_name;
    $university_email = $settings['email'] ?? $university_email;
    $university_website = $settings['website'] ?? $university_website;
}
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Certificate Verification - <?= htmlspecialchars($university_name) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%); min-height: 100vh; display: flex; align-items: center; }
        .verify-card { max-width: 600px; margin: 0 auto; border-radius: 12px; overflow: hidden; box-shadow: 0 10px 40px rgba(0,0,0,0.3); }
    </style>
</head>
<body>
<div class="container py-4">
    <div class="verify-card bg-white">
        <div class="text-center py-3" style="background:linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);color:white;">
            <?php if (file_exists('assets/img/Logo.png')): ?>
                <img src="assets/img/Logo.png" style="height:40px;" alt="Logo" class="mb-1"><br>
            <?php endif; ?>
            <h5 class="mb-0"><?= htmlspecialchars($university_name) ?></h5>
            <small>Certificate Verification Portal</small>
        </div>
        <div class="card-body p-4">
            <?php if ($student): ?>
                <div class="text-center mb-3">
                    <i class="bi bi-patch-check-fill text-success" style="font-size:3rem;"></i>
                    <h5 class="text-success mt-2">Certificate Verified</h5>
                    <p class="text-muted small mb-0">This certificate is authentic and valid.</p>
                </div>
                <table class="table table-sm table-borderless mb-0">
                    <tr><td class="text-muted fw-bold" style="width:40%;">Certificate No:</td><td><strong><?= htmlspecialchars($student['certificate_number']) ?></strong></td></tr>
                    <tr><td class="text-muted fw-bold">Student Name:</td><td><?= htmlspecialchars($student['full_name']) ?></td></tr>
                    <tr><td class="text-muted fw-bold">Student ID:</td><td><?= htmlspecialchars($student['student_id']) ?></td></tr>
                    <tr><td class="text-muted fw-bold">Program:</td><td><?= htmlspecialchars($student['program'] ?: ucfirst($student['program_type'])) ?></td></tr>
                    <tr><td class="text-muted fw-bold">Department:</td><td><?= htmlspecialchars($student['department'] ?: '—') ?></td></tr>
                    <tr><td class="text-muted fw-bold">Campus:</td><td><?= htmlspecialchars($student['campus'] ?: '—') ?></td></tr>
                    <tr><td class="text-muted fw-bold">Year of Study:</td><td>Year <?= $student['year_of_study'] ?></td></tr>
                    <tr><td class="text-muted fw-bold">Clearance Type:</td><td><?= ($student['clearance_type'] === 'midsemester') ? 'Mid-Semester' : 'End-of-Semester' ?></td></tr>
                    <tr><td class="text-muted fw-bold">Status:</td><td><span class="badge bg-success">CLEARED</span></td></tr>
                    <tr><td class="text-muted fw-bold">Cleared On:</td><td><?= date('M d, Y h:i A', strtotime($student['cleared_at'])) ?></td></tr>
                </table>
            <?php else: ?>
                <div class="text-center py-3">
                    <i class="bi bi-x-circle-fill text-danger" style="font-size:3rem;"></i>
                    <h5 class="text-danger mt-2">Verification Failed</h5>
                    <p class="text-muted"><?= htmlspecialchars($error) ?></p>
                </div>
            <?php endif; ?>
        </div>
        <div class="text-center py-2" style="background:#f8f9fa;font-size:12px;color:#888;">
            <?= htmlspecialchars($university_name) ?> &mdash; <?= htmlspecialchars($university_email) ?>
        </div>
    </div>
</div>
</body>
</html>
