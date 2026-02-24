<?php
// system_notifications.php - Admin system-wide notifications
require_once '../includes/auth.php';
require_once '../includes/email.php';
requireLogin();
requireRole(['staff', 'admin']);

$conn = getDbConnection();
$user = getCurrentUser();
$success = '';
$error = '';

// Handle maintenance notification
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_maintenance'])) {
    $maintenance_date = $_POST['maintenance_date'] ?? '';
    $start_time = $_POST['start_time'] ?? '';
    $end_time = $_POST['end_time'] ?? '';
    $affected_services = $_POST['affected_services'] ?? '';
    $maintenance_reason = $_POST['maintenance_reason'] ?? '';
    $recipient_type = $_POST['recipient_type'] ?? 'all';
    
    if (empty($maintenance_date) || empty($start_time) || empty($end_time)) {
        $error = "Please fill in all required fields.";
    } else if (isEmailEnabled()) {
        $sent_count = 0;
        $failed_count = 0;
        
        // Get recipients based on type
        $recipients = [];
        
        if ($recipient_type === 'all' || $recipient_type === 'students') {
            $result = $conn->query("SELECT full_name as name, email FROM students WHERE email IS NOT NULL AND email != ''");
            while ($row = $result->fetch_assoc()) {
                $recipients[] = $row;
            }
        }
        
        if ($recipient_type === 'all' || $recipient_type === 'lecturers') {
            $result = $conn->query("SELECT full_name as name, email FROM lecturers WHERE email IS NOT NULL AND email != ''");
            while ($row = $result->fetch_assoc()) {
                $recipients[] = $row;
            }
        }
        
        if ($recipient_type === 'all' || $recipient_type === 'staff') {
            $result = $conn->query("SELECT full_name as name, email FROM administrative_staff WHERE email IS NOT NULL AND email != ''");
            while ($row = $result->fetch_assoc()) {
                $recipients[] = $row;
            }
        }
        
        // Send emails
        foreach ($recipients as $recipient) {
            if (sendMaintenanceNotificationEmail(
                $recipient['email'],
                $recipient['name'],
                $maintenance_date,
                $start_time,
                $end_time,
                $affected_services,
                $maintenance_reason
            )) {
                $sent_count++;
            } else {
                $failed_count++;
            }
        }
        
        $success = "Maintenance notification sent to $sent_count recipients. Failed: $failed_count";
    } else {
        $error = "Email notifications are disabled. Please enable them in SMTP Settings.";
    }
}

// Handle policy update notification
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_policy'])) {
    $policy_type = $_POST['policy_type'] ?? '';
    $summary_of_changes = $_POST['summary_of_changes'] ?? '';
    $effective_date = $_POST['effective_date'] ?? '';
    $policy_url = $_POST['policy_url'] ?? '';
    $recipient_type = $_POST['recipient_type'] ?? 'all';
    
    if (empty($policy_type) || empty($summary_of_changes) || empty($effective_date)) {
        $error = "Please fill in all required fields.";
    } else if (isEmailEnabled()) {
        $sent_count = 0;
        $failed_count = 0;
        
        // Get recipients based on type
        $recipients = [];
        
        if ($recipient_type === 'all' || $recipient_type === 'students') {
            $result = $conn->query("SELECT full_name as name, email FROM students WHERE email IS NOT NULL AND email != ''");
            while ($row = $result->fetch_assoc()) {
                $recipients[] = $row;
            }
        }
        
        if ($recipient_type === 'all' || $recipient_type === 'lecturers') {
            $result = $conn->query("SELECT full_name as name, email FROM lecturers WHERE email IS NOT NULL AND email != ''");
            while ($row = $result->fetch_assoc()) {
                $recipients[] = $row;
            }
        }
        
        if ($recipient_type === 'all' || $recipient_type === 'staff') {
            $result = $conn->query("SELECT full_name as name, email FROM administrative_staff WHERE email IS NOT NULL AND email != ''");
            while ($row = $result->fetch_assoc()) {
                $recipients[] = $row;
            }
        }
        
        // Send emails
        foreach ($recipients as $recipient) {
            if (sendPolicyUpdateEmail(
                $recipient['email'],
                $recipient['name'],
                $policy_type,
                $summary_of_changes,
                $effective_date,
                $policy_url
            )) {
                $sent_count++;
            } else {
                $failed_count++;
            }
        }
        
        $success = "Policy update notification sent to $sent_count recipients. Failed: $failed_count";
    } else {
        $error = "Email notifications are disabled. Please enable them in SMTP Settings.";
    }
}

// Handle bulk notification
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_bulk'])) {
    $notification_title = $_POST['notification_title'] ?? '';
    $notification_body = $_POST['notification_body'] ?? '';
    $action_url = $_POST['action_url'] ?? '';
    $action_text = $_POST['action_text'] ?? 'View Details';
    $recipient_type = $_POST['recipient_type'] ?? 'all';
    
    if (empty($notification_title) || empty($notification_body)) {
        $error = "Please fill in the title and message.";
    } else if (isEmailEnabled()) {
        $recipients = [];
        
        if ($recipient_type === 'all' || $recipient_type === 'students') {
            $result = $conn->query("SELECT full_name as name, email FROM students WHERE email IS NOT NULL AND email != ''");
            while ($row = $result->fetch_assoc()) {
                $recipients[] = $row;
            }
        }
        
        if ($recipient_type === 'all' || $recipient_type === 'lecturers') {
            $result = $conn->query("SELECT full_name as name, email FROM lecturers WHERE email IS NOT NULL AND email != ''");
            while ($row = $result->fetch_assoc()) {
                $recipients[] = $row;
            }
        }
        
        if ($recipient_type === 'all' || $recipient_type === 'staff') {
            $result = $conn->query("SELECT full_name as name, email FROM administrative_staff WHERE email IS NOT NULL AND email != ''");
            while ($row = $result->fetch_assoc()) {
                $recipients[] = $row;
            }
        }
        
        $result = sendBulkNotificationEmail($recipients, $notification_title, $notification_title, $notification_body, $action_url, $action_text);
        $success = "Bulk notification sent. Success: {$result['success']}, Failed: {$result['failed']}";
    } else {
        $error = "Email notifications are disabled. Please enable them in SMTP Settings.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Notifications - Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
</head>
<body class="bg-light">
    <?php include 'header_nav.php'; ?>
    
    <div class="container-fluid py-4">
        <div class="row">
            <div class="col-12">
                <h2 class="mb-4"><i class="bi bi-megaphone me-2"></i>System Notifications</h2>
                
                <?php if ($success): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($success) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <i class="bi bi-exclamation-circle me-2"></i><?= htmlspecialchars($error) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <!-- Navigation Tabs -->
                <ul class="nav nav-tabs mb-4" role="tablist">
                    <li class="nav-item">
                        <a class="nav-link active" data-bs-toggle="tab" href="#maintenance">
                            <i class="bi bi-tools me-2"></i>Maintenance Notice
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" data-bs-toggle="tab" href="#policy">
                            <i class="bi bi-file-text me-2"></i>Policy Update
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" data-bs-toggle="tab" href="#bulk">
                            <i class="bi bi-send me-2"></i>Bulk Notification
                        </a>
                    </li>
                </ul>
                
                <div class="tab-content">
                    <!-- Maintenance Notice Tab -->
                    <div class="tab-pane fade show active" id="maintenance">
                        <div class="card shadow-sm">
                            <div class="card-header bg-warning text-dark">
                                <h5 class="mb-0"><i class="bi bi-tools me-2"></i>Schedule Maintenance Notification</h5>
                            </div>
                            <div class="card-body">
                                <form method="POST">
                                    <div class="row">
                                        <div class="col-md-4 mb-3">
                                            <label class="form-label">Maintenance Date <span class="text-danger">*</span></label>
                                            <input type="date" name="maintenance_date" class="form-control" required>
                                        </div>
                                        <div class="col-md-4 mb-3">
                                            <label class="form-label">Start Time <span class="text-danger">*</span></label>
                                            <input type="time" name="start_time" class="form-control" required>
                                        </div>
                                        <div class="col-md-4 mb-3">
                                            <label class="form-label">End Time (Estimated) <span class="text-danger">*</span></label>
                                            <input type="time" name="end_time" class="form-control" required>
                                        </div>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Affected Services</label>
                                        <textarea name="affected_services" class="form-control" rows="2" placeholder="e.g., Course access, Assignment submissions, Live sessions..."></textarea>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Reason for Maintenance</label>
                                        <textarea name="maintenance_reason" class="form-control" rows="2" placeholder="e.g., System upgrade, Database optimization..."></textarea>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Send To</label>
                                        <select name="recipient_type" class="form-select">
                                            <option value="all">All Users</option>
                                            <option value="students">Students Only</option>
                                            <option value="lecturers">Lecturers Only</option>
                                            <option value="staff">Staff Only</option>
                                        </select>
                                    </div>
                                    <button type="submit" name="send_maintenance" class="btn btn-warning">
                                        <i class="bi bi-send me-2"></i>Send Maintenance Notice
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Policy Update Tab -->
                    <div class="tab-pane fade" id="policy">
                        <div class="card shadow-sm">
                            <div class="card-header bg-info text-white">
                                <h5 class="mb-0"><i class="bi bi-file-text me-2"></i>Policy Update Notification</h5>
                            </div>
                            <div class="card-body">
                                <form method="POST">
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Policy Type <span class="text-danger">*</span></label>
                                            <select name="policy_type" class="form-select" required>
                                                <option value="">Select Policy Type</option>
                                                <option value="Terms of Service">Terms of Service</option>
                                                <option value="Privacy Policy">Privacy Policy</option>
                                                <option value="Academic Policy">Academic Policy</option>
                                                <option value="Payment Policy">Payment Policy</option>
                                                <option value="Attendance Policy">Attendance Policy</option>
                                                <option value="Code of Conduct">Code of Conduct</option>
                                                <option value="Other">Other</option>
                                            </select>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Effective Date <span class="text-danger">*</span></label>
                                            <input type="date" name="effective_date" class="form-control" required>
                                        </div>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Summary of Changes <span class="text-danger">*</span></label>
                                        <textarea name="summary_of_changes" class="form-control" rows="4" required placeholder="Describe the key changes in the policy..."></textarea>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Policy URL (Optional)</label>
                                        <input type="url" name="policy_url" class="form-control" placeholder="https://...">
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Send To</label>
                                        <select name="recipient_type" class="form-select">
                                            <option value="all">All Users</option>
                                            <option value="students">Students Only</option>
                                            <option value="lecturers">Lecturers Only</option>
                                            <option value="staff">Staff Only</option>
                                        </select>
                                    </div>
                                    <button type="submit" name="send_policy" class="btn btn-info text-white">
                                        <i class="bi bi-send me-2"></i>Send Policy Update
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Bulk Notification Tab -->
                    <div class="tab-pane fade" id="bulk">
                        <div class="card shadow-sm">
                            <div class="card-header bg-primary text-white">
                                <h5 class="mb-0"><i class="bi bi-send me-2"></i>Send Bulk Notification</h5>
                            </div>
                            <div class="card-body">
                                <form method="POST">
                                    <div class="mb-3">
                                        <label class="form-label">Notification Title <span class="text-danger">*</span></label>
                                        <input type="text" name="notification_title" class="form-control" required placeholder="Enter notification title...">
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Message <span class="text-danger">*</span></label>
                                        <textarea name="notification_body" class="form-control" rows="5" required placeholder="Enter your message..."></textarea>
                                    </div>
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Action URL (Optional)</label>
                                            <input type="url" name="action_url" class="form-control" placeholder="https://...">
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Action Button Text</label>
                                            <input type="text" name="action_text" class="form-control" value="View Details">
                                        </div>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Send To</label>
                                        <select name="recipient_type" class="form-select">
                                            <option value="all">All Users</option>
                                            <option value="students">Students Only</option>
                                            <option value="lecturers">Lecturers Only</option>
                                            <option value="staff">Staff Only</option>
                                        </select>
                                    </div>
                                    <button type="submit" name="send_bulk" class="btn btn-primary">
                                        <i class="bi bi-send me-2"></i>Send Notification
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
