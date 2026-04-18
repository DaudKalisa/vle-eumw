                                                                                                                                                        <?php
/**
 * Attendance Confirmation - Student QR scan / code entry
 * Uses attendance_sessions + attendance_records tables
 */
require_once '../includes/auth.php';
requireLogin();
requireRole(['student']);

$conn = getDbConnection();
$student_id = $_SESSION['vle_related_id'];
$session_code = $_GET['session'] ?? '';
$message = '';

if ($session_code) {
    // Find the session
    $stmt = $conn->prepare("SELECT session_id, course_id, is_active FROM attendance_sessions WHERE session_code = ? LIMIT 1");
    $stmt->bind_param("s", $session_code);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $session = $result->fetch_assoc();
        if (!$session['is_active']) {
            $message = '<div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-2"></i>Attendance for this session is closed.</div>';
        } else {
            // Check if already marked
            $stmt2 = $conn->prepare("SELECT record_id FROM attendance_records WHERE session_id = ? AND student_id = ?");
            $stmt2->bind_param("is", $session['session_id'], $student_id);
            $stmt2->execute();
            $stmt2->store_result();
            if ($stmt2->num_rows > 0) {
                $message = '<div class="alert alert-info"><i class="bi bi-info-circle me-2"></i>You have already registered attendance for this session.</div>';
            } else {
                // Mark attendance
                $now = date('Y-m-d H:i:s');
                $ip = $_SERVER['REMOTE_ADDR'] ?? '';
                $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
                $stmt3 = $conn->prepare("INSERT INTO attendance_records (session_id, student_id, check_in_time, status, source, ip_address, user_agent) VALUES (?, ?, ?, 'present', 'qr_scan', ?, ?)");
                $stmt3->bind_param("issss", $session['session_id'], $student_id, $now, $ip, $ua);
                if ($stmt3->execute()) {
                    $message = '<div class="alert alert-success"><i class="bi bi-check-circle me-2"></i>Attendance successfully registered!</div>';
                } else {
                    $message = '<div class="alert alert-danger"><i class="bi bi-x-circle me-2"></i>Error registering attendance. Please try again.</div>';
                }
                $stmt3->close();
            }
            $stmt2->close();
        }
    } else {
        $message = '<div class="alert alert-danger"><i class="bi bi-x-circle me-2"></i>Invalid or expired session code.</div>';
    }
    $stmt->close();
} else {
    // Manual code entry
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['manual_session_code'])) {
        $manual_code = trim($_POST['manual_session_code']);
        if ($manual_code !== '') {
            header('Location: attendance_confirm.php?session=' . urlencode($manual_code));
            exit();
        } else {
            $message = '<div class="alert alert-danger"><i class="bi bi-x-circle me-2"></i>Please enter a session code.</div>';
        }
    }
}

$page_title = "Attendance Check-In";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance Check-In - VLE</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
</head>
<body>
<?php include 'header_nav.php'; ?>
<div class="vle-content">
    <div class="row justify-content-center">
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-success text-white text-center">
                    <h4 class="mb-0"><i class="bi bi-qr-code-scan me-2"></i>Attendance Check-In</h4>
                </div>
                <div class="card-body p-4 text-center">
                    <?= $message ?>
                    <?php if (!$session_code): ?>
                        <div class="mb-4">
                            <i class="bi bi-camera display-4 text-primary d-block mb-2"></i>
                            <button type="button" class="btn btn-outline-primary w-100 mb-3" onclick="openQRScanner()">
                                <i class="bi bi-qr-code-scan me-2"></i>Scan QR Code with Camera
                            </button>
                        </div>
                        <!-- QR Scanner Modal -->
                        <div class="modal fade" id="qrScannerModal" tabindex="-1">
                            <div class="modal-dialog modal-dialog-centered">
                                <div class="modal-content">
                                    <div class="modal-header bg-primary text-white">
                                        <h5 class="modal-title"><i class="bi bi-qr-code-scan me-2"></i>Scan QR Code</h5>
                                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                    </div>
                                    <div class="modal-body text-center">
                                        <div id="qr-reader" style="width:100%;max-width:320px;margin:0 auto;"></div>
                                        <div id="qr-result" class="mt-3 text-success fw-bold"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <hr>
                        <p class="text-muted mb-2">Or enter the session code manually:</p>
                        <form method="POST" class="mb-3">
                            <div class="input-group">
                                <input type="text" name="manual_session_code" class="form-control form-control-lg text-center font-monospace" placeholder="Enter code" required autofocus maxlength="10">
                                <button type="submit" class="btn btn-success"><i class="bi bi-check-lg me-1"></i>Submit</button>
                            </div>
                        </form>
                    <?php endif; ?>
                    <div class="mt-3">
                        <a href="attendance_register.php" class="btn btn-outline-primary me-2"><i class="bi bi-clipboard-data me-1"></i>My Attendance</a>
                        <a href="dashboard.php" class="btn btn-outline-secondary"><i class="bi bi-house me-1"></i>Dashboard</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://unpkg.com/html5-qrcode@2.3.8/minified/html5-qrcode.min.js"></script>
<script>
function openQRScanner() {
    var modal = new bootstrap.Modal(document.getElementById('qrScannerModal'));
    modal.show();
    setTimeout(function() {
        if (window.qrScannerInstance) { try { window.qrScannerInstance.stop(); } catch(e){} }
        window.qrScannerInstance = new Html5Qrcode("qr-reader");
        window.qrScannerInstance.start(
            { facingMode: "environment" },
            { fps: 10, qrbox: 250 },
            function(qrCodeMessage) {
                document.getElementById('qr-result').innerText = 'QR Code detected! Redirecting...';
                window.qrScannerInstance.stop();
                if (/^[A-Z0-9]{6,10}$/i.test(qrCodeMessage)) {
                    window.location.href = 'attendance_confirm.php?session=' + encodeURIComponent(qrCodeMessage);
                } else {
                    window.location.href = qrCodeMessage;
                }
            },
            function() {}
        );
    }, 500);
}
document.addEventListener('DOMContentLoaded', function() {
    var el = document.getElementById('qrScannerModal');
    if (el) el.addEventListener('hidden.bs.modal', function() {
        if (window.qrScannerInstance) { try { window.qrScannerInstance.stop(); } catch(e){} }
    });
});
</script>
</body>
</html>
