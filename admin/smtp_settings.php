<?php
// admin/smtp_settings.php - Manage SMTP email settings for no-reply notifications
require_once '../includes/auth.php';
requireLogin();
requireRole(['staff', 'admin']);

$conn = getDbConnection();
$user = getCurrentUser();
$message = '';
$error = '';
$test_result = '';

// Handle settings update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_smtp_settings'])) {
    $smtp_host = trim($_POST['smtp_host']);
    $smtp_port = (int)$_POST['smtp_port'];
    $smtp_username = trim($_POST['smtp_username']);
    $smtp_password = trim($_POST['smtp_password']);
    $smtp_encryption = $_POST['smtp_encryption'] ?? 'tls';
    $smtp_from_email = trim($_POST['smtp_from_email']);
    $smtp_from_name = trim($_POST['smtp_from_name']);
    $smtp_reply_to_email = trim($_POST['smtp_reply_to_email'] ?? '');
    $smtp_reply_to_name = trim($_POST['smtp_reply_to_name'] ?? '');
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    $enable_email_notifications = isset($_POST['enable_email_notifications']) ? 1 : 0;
    
    // Validate inputs
    if (empty($smtp_host) || empty($smtp_username) || empty($smtp_from_email)) {
        $error = "SMTP host, username, and from email are required!";
    } elseif (!filter_var($smtp_from_email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid 'From' email address!";
    } elseif (!empty($smtp_reply_to_email) && !filter_var($smtp_reply_to_email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid 'Reply-To' email address!";
    } elseif ($smtp_port < 1 || $smtp_port > 65535) {
        $error = "Invalid port number. Common ports: 587 (TLS), 465 (SSL), 25 (unencrypted)";
    } else {
        // Check if settings already exist
        $check = $conn->query("SELECT setting_id FROM smtp_settings LIMIT 1");
        if (!$check) {
            $error = "Database table 'smtp_settings' needs to be created. Please run setup_smtp_settings.php first!";
        } else {
            $check_result = $check;
            
            if ($check_result->num_rows > 0) {
                $row = $check_result->fetch_assoc();
                // Update existing
                $stmt = $conn->prepare("UPDATE smtp_settings SET 
                    smtp_host = ?, smtp_port = ?, smtp_username = ?, smtp_password = ?,
                    smtp_encryption = ?, smtp_from_email = ?, smtp_from_name = ?,
                    smtp_reply_to_email = ?, smtp_reply_to_name = ?,
                    is_active = ?, enable_email_notifications = ?
                    WHERE setting_id = ?");
                $stmt->bind_param("sisssssssiii", 
                    $smtp_host, $smtp_port, $smtp_username, $smtp_password,
                    $smtp_encryption, $smtp_from_email, $smtp_from_name,
                    $smtp_reply_to_email, $smtp_reply_to_name,
                    $is_active, $enable_email_notifications, $row['setting_id']);
            } else {
                // Insert new
                $stmt = $conn->prepare("INSERT INTO smtp_settings 
                    (smtp_host, smtp_port, smtp_username, smtp_password, smtp_encryption, 
                     smtp_from_email, smtp_from_name, smtp_reply_to_email, smtp_reply_to_name,
                     is_active, enable_email_notifications) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("sisssssssii", 
                    $smtp_host, $smtp_port, $smtp_username, $smtp_password,
                    $smtp_encryption, $smtp_from_email, $smtp_from_name,
                    $smtp_reply_to_email, $smtp_reply_to_name,
                    $is_active, $enable_email_notifications);
            }
            
            if ($stmt->execute()) {
                $message = "SMTP settings saved successfully!";
                $stmt->close();
            } else {
                $error = "Error saving SMTP settings: " . $stmt->error;
                $stmt->close();
            }
        }
    }
}

// Handle test email
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_test_email'])) {
    $test_email = trim($_POST['test_email']);
    
    if (empty($test_email) || !filter_var($test_email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid test email address!";
    } else {
        // Load SMTP settings
        $smtp_config = $conn->query("SELECT * FROM smtp_settings WHERE is_active = 1 LIMIT 1");
        if ($smtp_config && $smtp_config->num_rows > 0) {
            $config = $smtp_config->fetch_assoc();
            
            // Try to send test email using PHPMailer
            require_once __DIR__ . '/../vendor/autoload.php';
            
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            
            try {
                // Server settings
                $mail->isSMTP();
                $mail->Host = $config['smtp_host'];
                $mail->SMTPAuth = true;
                $mail->Username = $config['smtp_username'];
                $mail->Password = $config['smtp_password'];
                $mail->SMTPSecure = $config['smtp_encryption'] === 'none' ? false : $config['smtp_encryption'];
                $mail->Port = $config['smtp_port'];
                
                // Recipients
                $mail->setFrom($config['smtp_from_email'], $config['smtp_from_name']);
                $mail->addAddress($test_email);
                
                if (!empty($config['smtp_reply_to_email'])) {
                    $mail->addReplyTo($config['smtp_reply_to_email'], $config['smtp_reply_to_name'] ?: $config['smtp_from_name']);
                }
                
                // Content
                $mail->isHTML(true);
                $mail->Subject = 'VLE System - Test Email';
                $mail->Body = '
                <html>
                <head>
                    <style>
                        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                        .header { background-color: #28a745; color: white; padding: 20px; text-align: center; border-radius: 8px 8px 0 0; }
                        .content { background-color: #f8f9fa; padding: 30px; border-radius: 0 0 8px 8px; }
                        .success-icon { font-size: 48px; }
                        .footer { text-align: center; color: #6c757d; font-size: 12px; margin-top: 20px; }
                    </style>
                </head>
                <body>
                    <div class="container">
                        <div class="header">
                            <div class="success-icon">✓</div>
                            <h2>SMTP Configuration Test Successful!</h2>
                        </div>
                        <div class="content">
                            <p>Hello,</p>
                            <p>This is a test email from your VLE System to verify that your SMTP settings are configured correctly.</p>
                            <p><strong>Configuration Details:</strong></p>
                            <ul>
                                <li>SMTP Host: ' . htmlspecialchars($config['smtp_host']) . '</li>
                                <li>Port: ' . htmlspecialchars($config['smtp_port']) . '</li>
                                <li>Encryption: ' . htmlspecialchars(strtoupper($config['smtp_encryption'])) . '</li>
                                <li>From: ' . htmlspecialchars($config['smtp_from_name']) . ' &lt;' . htmlspecialchars($config['smtp_from_email']) . '&gt;</li>
                            </ul>
                            <p>If you received this email, your SMTP configuration is working properly!</p>
                        </div>
                        <div class="footer">
                            <p>This is an automated test email from VLE System.</p>
                            <p>Sent on: ' . date('F j, Y g:i A') . '</p>
                        </div>
                    </div>
                </body>
                </html>';
                $mail->AltBody = 'This is a test email from VLE System. If you received this, your SMTP settings are working correctly.';
                
                $mail->send();
                
                // Update test status in database
                $conn->query("UPDATE smtp_settings SET test_email_sent = 1, last_test_date = NOW() WHERE setting_id = " . $config['setting_id']);
                
                $test_result = "success";
                $message = "Test email sent successfully to " . htmlspecialchars($test_email) . "!";
                
            } catch (Exception $e) {
                $test_result = "error";
                $error = "Failed to send test email. Error: " . $mail->ErrorInfo;
            }
        } else {
            $error = "Please save SMTP settings first before sending a test email!";
        }
    }
}

// Get current SMTP settings
$smtp_settings = null;
$result = $conn->query("SELECT * FROM smtp_settings ORDER BY is_active DESC, created_at DESC LIMIT 1");
if ($result && $result->num_rows > 0) {
    $smtp_settings = $result->fetch_assoc();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SMTP Email Settings - Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
    <style>
        .password-toggle {
            cursor: pointer;
            border: none;
            background: none;
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            color: #6c757d;
        }
        .password-wrapper { position: relative; }
        .preset-btn { font-size: 0.85rem; }
    </style>
</head>
<body>
    <?php 
    $breadcrumbs = [['title' => 'SMTP Settings']];
    include 'header_nav.php'; 
    ?>

    <div class="vle-content">
        <!-- Page Header -->
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
            <div>
                <h2 class="vle-page-title"><i class="bi bi-envelope-gear"></i> SMTP Email Configuration</h2>
                <p class="text-muted mb-0">Configure your email server for system notifications</p>
            </div>
        </div>

        <!-- Messages -->
        <?php if ($message): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="bi bi-check-circle"></i> <?php echo $message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-triangle"></i> <?php echo $error; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="row">
            <div class="col-lg-8">
                <!-- Configuration Form -->
                <div class="card mb-4">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="bi bi-gear"></i> SMTP Server Configuration</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="update_smtp_settings" value="1">
                            
                            <!-- Quick Preset Buttons -->
                            <div class="alert alert-info mb-4">
                                <i class="bi bi-lightning-fill"></i> <strong>Quick Setup:</strong> Select a preset to auto-fill common SMTP settings
                                <div class="mt-2">
                                    <button type="button" class="btn btn-outline-primary btn-sm preset-btn me-1" onclick="fillPreset('gmail')">
                                        <i class="bi bi-google"></i> Gmail
                                    </button>
                                    <button type="button" class="btn btn-outline-primary btn-sm preset-btn me-1" onclick="fillPreset('outlook')">
                                        <i class="bi bi-microsoft"></i> Outlook/Office365
                                    </button>
                                    <button type="button" class="btn btn-outline-primary btn-sm preset-btn me-1" onclick="fillPreset('yahoo')">
                                        <i class="bi bi-envelope"></i> Yahoo
                                    </button>
                                    <button type="button" class="btn btn-outline-secondary btn-sm preset-btn" onclick="fillPreset('custom')">
                                        <i class="bi bi-sliders"></i> Custom
                                    </button>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-8 mb-3">
                                    <label class="form-label fw-bold"><i class="bi bi-server"></i> SMTP Host *</label>
                                    <input type="text" class="form-control" id="smtp_host" name="smtp_host" 
                                           value="<?php echo $smtp_settings ? htmlspecialchars($smtp_settings['smtp_host']) : 'smtp.gmail.com'; ?>"
                                           placeholder="smtp.gmail.com" required>
                                    <small class="text-muted">SMTP server address (e.g., smtp.gmail.com, smtp.office365.com)</small>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label fw-bold"><i class="bi bi-diagram-2"></i> Port *</label>
                                    <input type="number" class="form-control" id="smtp_port" name="smtp_port" 
                                           value="<?php echo $smtp_settings ? htmlspecialchars($smtp_settings['smtp_port']) : '587'; ?>"
                                           min="1" max="65535" required>
                                    <small class="text-muted">587 (TLS) or 465 (SSL)</small>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-bold"><i class="bi bi-person"></i> SMTP Username *</label>
                                    <input type="text" class="form-control" name="smtp_username" 
                                           value="<?php echo $smtp_settings ? htmlspecialchars($smtp_settings['smtp_username']) : ''; ?>"
                                           placeholder="your-email@gmail.com" required>
                                    <small class="text-muted">Usually your full email address</small>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-bold"><i class="bi bi-key"></i> SMTP Password *</label>
                                    <div class="password-wrapper">
                                        <input type="password" class="form-control" id="smtp_password" name="smtp_password" 
                                               value="<?php echo $smtp_settings ? htmlspecialchars($smtp_settings['smtp_password']) : ''; ?>"
                                               placeholder="App password or SMTP password" required>
                                        <button type="button" class="password-toggle" onclick="togglePassword('smtp_password')">
                                            <i class="bi bi-eye" id="smtp_password_icon"></i>
                                        </button>
                                    </div>
                                    <small class="text-muted">For Gmail, use an <a href="https://support.google.com/accounts/answer/185833" target="_blank">App Password</a></small>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-bold"><i class="bi bi-shield-lock"></i> Encryption</label>
                                <select class="form-select" id="smtp_encryption" name="smtp_encryption">
                                    <option value="tls" <?php echo ($smtp_settings && $smtp_settings['smtp_encryption'] == 'tls') ? 'selected' : (!$smtp_settings ? 'selected' : ''); ?>>TLS (Recommended - Port 587)</option>
                                    <option value="ssl" <?php echo ($smtp_settings && $smtp_settings['smtp_encryption'] == 'ssl') ? 'selected' : ''; ?>>SSL (Port 465)</option>
                                    <option value="none" <?php echo ($smtp_settings && $smtp_settings['smtp_encryption'] == 'none') ? 'selected' : ''; ?>>None (Not Recommended)</option>
                                </select>
                            </div>

                            <hr class="my-4">
                            <h6 class="text-muted mb-3"><i class="bi bi-envelope"></i> Sender Information</h6>

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-bold"><i class="bi bi-at"></i> From Email *</label>
                                    <input type="email" class="form-control" name="smtp_from_email" 
                                           value="<?php echo $smtp_settings ? htmlspecialchars($smtp_settings['smtp_from_email']) : ''; ?>"
                                           placeholder="noreply@university.edu" required>
                                    <small class="text-muted">The "From" address recipients will see</small>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-bold"><i class="bi bi-person-badge"></i> From Name</label>
                                    <input type="text" class="form-control" name="smtp_from_name" 
                                           value="<?php echo $smtp_settings ? htmlspecialchars($smtp_settings['smtp_from_name']) : 'VLE System'; ?>"
                                           placeholder="VLE System">
                                    <small class="text-muted">Display name for the sender</small>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label"><i class="bi bi-reply"></i> Reply-To Email (Optional)</label>
                                    <input type="email" class="form-control" name="smtp_reply_to_email" 
                                           value="<?php echo $smtp_settings ? htmlspecialchars($smtp_settings['smtp_reply_to_email']) : ''; ?>"
                                           placeholder="support@university.edu">
                                    <small class="text-muted">Where replies should be sent</small>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label"><i class="bi bi-person"></i> Reply-To Name (Optional)</label>
                                    <input type="text" class="form-control" name="smtp_reply_to_name" 
                                           value="<?php echo $smtp_settings ? htmlspecialchars($smtp_settings['smtp_reply_to_name']) : ''; ?>"
                                           placeholder="University Support">
                                </div>
                            </div>

                            <hr class="my-4">
                            <h6 class="text-muted mb-3"><i class="bi bi-toggles"></i> Options</h6>

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" name="is_active" id="is_active" 
                                               <?php echo (!$smtp_settings || $smtp_settings['is_active']) ? 'checked' : ''; ?>>
                                        <label class="form-check-label fw-bold" for="is_active">Enable SMTP Configuration</label>
                                    </div>
                                    <small class="text-muted">When disabled, system will fall back to hardcoded settings</small>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" name="enable_email_notifications" id="enable_email_notifications" 
                                               <?php echo (!$smtp_settings || $smtp_settings['enable_email_notifications']) ? 'checked' : ''; ?>>
                                        <label class="form-check-label fw-bold" for="enable_email_notifications">Enable Email Notifications</label>
                                    </div>
                                    <small class="text-muted">Master switch for all system email notifications</small>
                                </div>
                            </div>

                            <div class="d-flex gap-2 mt-4">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-save me-2"></i>Save Settings
                                </button>
                                <a href="dashboard.php" class="btn btn-outline-secondary">
                                    <i class="bi bi-arrow-left me-2"></i>Back
                                </a>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Test Email Section -->
                <div class="card mb-4">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0"><i class="bi bi-send"></i> Test Email Configuration</h5>
                    </div>
                    <div class="card-body">
                        <p class="text-muted">Send a test email to verify your SMTP settings are working correctly.</p>
                        
                        <form method="POST" class="d-flex gap-2 align-items-end flex-wrap">
                            <input type="hidden" name="send_test_email" value="1">
                            <div class="flex-grow-1">
                                <label class="form-label">Test Email Address</label>
                                <input type="email" class="form-control" name="test_email" 
                                       placeholder="test@example.com" 
                                       value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>" required>
                            </div>
                            <button type="submit" class="btn btn-success">
                                <i class="bi bi-send me-2"></i>Send Test Email
                            </button>
                        </form>

                        <?php if ($smtp_settings && $smtp_settings['test_email_sent'] && $smtp_settings['last_test_date']): ?>
                            <div class="alert alert-info mt-3 mb-0">
                                <i class="bi bi-info-circle"></i> Last test email sent: <?php echo date('M j, Y g:i A', strtotime($smtp_settings['last_test_date'])); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Help Sidebar -->
            <div class="col-lg-4">
                <!-- Current Status -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h6 class="mb-0"><i class="bi bi-info-circle"></i> Current Status</h6>
                    </div>
                    <div class="card-body">
                        <?php if ($smtp_settings): ?>
                            <div class="d-flex align-items-center mb-2">
                                <?php if ($smtp_settings['is_active']): ?>
                                    <span class="badge bg-success me-2"><i class="bi bi-check-circle"></i> Active</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary me-2"><i class="bi bi-x-circle"></i> Inactive</span>
                                <?php endif; ?>
                                <?php if ($smtp_settings['enable_email_notifications']): ?>
                                    <span class="badge bg-info"><i class="bi bi-envelope"></i> Emails On</span>
                                <?php else: ?>
                                    <span class="badge bg-warning"><i class="bi bi-envelope-slash"></i> Emails Off</span>
                                <?php endif; ?>
                            </div>
                            <hr>
                            <small class="text-muted">
                                <strong>Host:</strong> <?php echo htmlspecialchars($smtp_settings['smtp_host']); ?><br>
                                <strong>Port:</strong> <?php echo htmlspecialchars($smtp_settings['smtp_port']); ?><br>
                                <strong>Encryption:</strong> <?php echo strtoupper($smtp_settings['smtp_encryption']); ?><br>
                                <strong>From:</strong> <?php echo htmlspecialchars($smtp_settings['smtp_from_email']); ?>
                            </small>
                        <?php else: ?>
                            <div class="text-center text-muted py-3">
                                <i class="bi bi-envelope-x display-6"></i>
                                <p class="mt-2 mb-0">No SMTP settings configured yet</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Gmail Setup Guide -->
                <div class="card mb-4">
                    <div class="card-header bg-danger text-white">
                        <h6 class="mb-0"><i class="bi bi-google"></i> Gmail Setup Guide</h6>
                    </div>
                    <div class="card-body">
                        <p class="small">To use Gmail SMTP, you need to create an <strong>App Password</strong>:</p>
                        <ol class="small mb-0">
                            <li>Go to <a href="https://myaccount.google.com/security" target="_blank">Google Account Security</a></li>
                            <li>Enable <strong>2-Step Verification</strong> if not already enabled</li>
                            <li>Go to <a href="https://myaccount.google.com/apppasswords" target="_blank">App Passwords</a></li>
                            <li>Select "Mail" and your device</li>
                            <li>Click "Generate" and copy the 16-character password</li>
                            <li>Use this password in the SMTP Password field</li>
                        </ol>
                        <div class="alert alert-warning mt-3 mb-0 small">
                            <i class="bi bi-exclamation-triangle"></i> Do NOT use your regular Gmail password!
                        </div>
                    </div>
                </div>

                <!-- Common SMTP Settings -->
                <div class="card">
                    <div class="card-header">
                        <h6 class="mb-0"><i class="bi bi-list-check"></i> Common SMTP Settings</h6>
                    </div>
                    <div class="card-body p-0">
                        <table class="table table-sm mb-0">
                            <thead class="table-light">
                                <tr><th>Provider</th><th>Host</th><th>Port</th></tr>
                            </thead>
                            <tbody class="small">
                                <tr><td>Gmail</td><td>smtp.gmail.com</td><td>587/TLS</td></tr>
                                <tr><td>Outlook</td><td>smtp.office365.com</td><td>587/TLS</td></tr>
                                <tr><td>Yahoo</td><td>smtp.mail.yahoo.com</td><td>587/TLS</td></tr>
                                <tr><td>Zoho</td><td>smtp.zoho.com</td><td>587/TLS</td></tr>
                                <tr><td>SendGrid</td><td>smtp.sendgrid.net</td><td>587/TLS</td></tr>
                                <tr><td>Mailgun</td><td>smtp.mailgun.org</td><td>587/TLS</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Toggle password visibility
        function togglePassword(inputId) {
            const input = document.getElementById(inputId);
            const icon = document.getElementById(inputId + '_icon');
            
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.remove('bi-eye');
                icon.classList.add('bi-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.remove('bi-eye-slash');
                icon.classList.add('bi-eye');
            }
        }
        
        // Fill SMTP presets
        function fillPreset(provider) {
            const presets = {
                gmail: { host: 'smtp.gmail.com', port: 587, encryption: 'tls' },
                outlook: { host: 'smtp.office365.com', port: 587, encryption: 'tls' },
                yahoo: { host: 'smtp.mail.yahoo.com', port: 587, encryption: 'tls' },
                custom: { host: '', port: 587, encryption: 'tls' }
            };
            
            const preset = presets[provider];
            if (preset) {
                document.getElementById('smtp_host').value = preset.host;
                document.getElementById('smtp_port').value = preset.port;
                document.getElementById('smtp_encryption').value = preset.encryption;
                
                if (provider !== 'custom') {
                    document.getElementById('smtp_host').focus();
                }
            }
        }
    </script>
</body>
</html>
