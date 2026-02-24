<?php
// attendance_confirm.php - Student QR scan/confirmation logic
require_once '../includes/auth.php';
requireLogin();
requireRole(['student']);

$conn = getDbConnection();
$student_id = $_SESSION['vle_related_id'];
$session_code = $_GET['session'] ?? '';
$message = '';

if ($session_code) {
    // Find the session
    $stmt = $conn->prepare("SELECT session_id, course_id, is_completed FROM vle_class_sessions WHERE session_code = ? LIMIT 1");
    $stmt->bind_param("s", $session_code);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $session = $result->fetch_assoc();
        if ($session['is_completed']) {
            $message = '<div class="alert alert-warning">Attendance for this session is closed.</div>';
        } else {
            // Check if already marked
            $stmt2 = $conn->prepare("SELECT attendance_id FROM vle_attendance WHERE session_id = ? AND student_id = ?");
            $stmt2->bind_param("is", $session['session_id'], $student_id);
            $stmt2->execute();
            $stmt2->store_result();
            if ($stmt2->num_rows > 0) {
                $message = '<div class="alert alert-info">You have already registered attendance for this session.</div>';
            } else {
                // Mark attendance
                $stmt3 = $conn->prepare("INSERT INTO vle_attendance (session_id, course_id, student_id, attended) VALUES (?, ?, ?, 1)");
                $stmt3->bind_param("iis", $session['session_id'], $session['course_id'], $student_id);
                if ($stmt3->execute()) {
                    $message = '<div class="alert alert-success">Attendance successfully registered!</div>';
                } else {
                    $message = '<div class="alert alert-danger">Error registering attendance. Please try again.</div>';
                }
                $stmt3->close();
            }
            $stmt2->close();
        }
    } else {
        $message = '<div class="alert alert-danger">Invalid or expired session code.</div>';
    }
    $stmt->close();
} else {
    // If no session code, show entry form
    $message = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['manual_session_code'])) {
        $manual_code = trim($_POST['manual_session_code']);
        if ($manual_code !== '') {
            // Redirect to self with session code in URL
            header('Location: attendance_confirm.php?session=' . urlencode($manual_code));
            exit();
        } else {
            $message = '<div class="alert alert-danger">Please enter a session code.</div>';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance Confirmation</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="manifest" href="/manifest.json">
    <meta name="theme-color" content="#198754">
    <script>
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', function() {
                navigator.serviceWorker.register('/service-worker.js');
            });
        }
    </script>
</head>
<body>
    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-lg-6">
                <div class="card shadow-lg border-0">
                    <div class="card-header bg-success text-white text-center">
                        <h3 class="mb-0">Attendance Confirmation</h3>
                    </div>
                    <div class="card-body p-4 text-center">
                        <?php echo $message; ?>
                        <?php if (!$session_code): ?>
                            <button type="button" class="btn btn-outline-primary w-100 mb-3" onclick="openQRScanner()">
                                <i class="bi bi-camera" style="font-size: 2rem;"></i> Scan QR Code with Camera
                            </button>
                            <!-- Modal for QR Scanner -->
                            <div class="modal fade" id="qrScannerModal" tabindex="-1" aria-labelledby="qrScannerModalLabel" aria-hidden="true">
                              <div class="modal-dialog modal-dialog-centered">
                                <div class="modal-content">
                                  <div class="modal-header">
                                    <h5 class="modal-title" id="qrScannerModalLabel">Scan Attendance QR Code</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                  </div>
                                  <div class="modal-body text-center">
                                    <video id="qr-video" width="100%" style="max-width:320px;" autoplay></video>
                                    <div id="qr-result" class="mt-3 text-success fw-bold"></div>
                                  </div>
                                </div>
                              </div>
                            </div>
                            <form method="POST" class="mb-3">
                                <label for="manual_session_code" class="form-label">Enter Session Code</label>
                                <input type="text" name="manual_session_code" id="manual_session_code" class="form-control mb-2" placeholder="e.g. 4896abe949b13eda" required autofocus>
                                <button type="submit" class="btn btn-primary w-100">Submit</button>
                            </form>
                            <div class="text-muted mb-2">Or scan the QR code provided by your lecturer.</div>
                            <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
                            <script src="https://unpkg.com/html5-qrcode@2.3.8/minified/html5-qrcode.min.js"></script>
                            <script>
                            function openQRScanner() {
                                var modal = new bootstrap.Modal(document.getElementById('qrScannerModal'));
                                modal.show();
                                setTimeout(function() {
                                    if (window.qrScannerInstance) {
                                        window.qrScannerInstance.stop();
                                    }
                                    window.qrScannerInstance = new Html5Qrcode("qr-video");
                                    window.qrScannerInstance.start(
                                        { facingMode: "environment" },
                                        { fps: 10, qrbox: 250 },
                                        qrCodeMessage => {
                                            document.getElementById('qr-result').innerText = 'QR Code detected! Redirecting...';
                                            // If the QR code is just a session code, redirect to self with ?session=...
                                            if (/^[a-f0-9]{16,}$/.test(qrCodeMessage)) {
                                                window.location.href = 'attendance_confirm.php?session=' + encodeURIComponent(qrCodeMessage);
                                            } else {
                                                // If it's a full URL, redirect directly
                                                window.location.href = qrCodeMessage;
                                            }
                                            window.qrScannerInstance.stop();
                                        },
                                        errorMessage => {
                                            // Optionally show scan errors
                                        }
                                    );
                                }, 500);
                            }
                            document.addEventListener('DOMContentLoaded', function() {
                                var modalEl = document.getElementById('qrScannerModal');
                                if (modalEl) {
                                    modalEl.addEventListener('hidden.bs.modal', function () {
                                        if (window.qrScannerInstance) {
                                            window.qrScannerInstance.stop();
                                        }
                                        document.getElementById('qr-result').innerText = '';
                                    });
                                }
                            });
                            </script>
                        <?php endif; ?>
                        <a href="dashboard.php" class="btn btn-outline-secondary mt-3">Back to Dashboard</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
