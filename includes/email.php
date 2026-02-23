<?php
// email.php - Email configuration and helper functions for VLE System

// Fallback Email configuration (used if database settings are not available)
// These can be overridden by admin in SMTP Settings
define('SMTP_HOST', 'smtp.gmail.com'); // e.g., smtp.gmail.com
define('SMTP_PORT', 587);
define('SMTP_USERNAME', 'your-email@gmail.com'); // Your email address
define('SMTP_PASSWORD', 'your-app-password'); // Your app password
define('SMTP_FROM_EMAIL', 'your-email@gmail.com');
define('SMTP_FROM_NAME', 'VLE System');
define('SMTP_ENCRYPTION', 'tls'); // 'tls' or 'ssl'

// System URL - Auto-detected from SITE_URL (set in config.php / config.production.php)
if (defined('SITE_URL')) {
    define('SYSTEM_URL', rtrim(SITE_URL, '/'));
} elseif (defined('APP_ENV') && APP_ENV === 'production') {
    define('SYSTEM_URL', 'https://vle.exploitsonline.com');
} else {
    // Fallback for local development
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    define('SYSTEM_URL', $protocol . '://' . $host . '/vle-eumw');
}

/**
 * Get university settings from database
 */
function getUniversitySettings() {
    static $settings = null;
    
    if ($settings !== null) {
        return $settings;
    }
    
    try {
        require_once __DIR__ . '/config.php';
        $conn = getDbConnection();
        $result = $conn->query("SELECT * FROM university_settings LIMIT 1");
        if ($result && $result->num_rows > 0) {
            $settings = $result->fetch_assoc();
            return $settings;
        }
    } catch (Exception $e) {
        error_log("Failed to load university settings: " . $e->getMessage());
    }
    
    // Default settings
    $settings = [
        'university_name' => 'University of Excellence',
        'email' => 'info@university.edu',
        'phone' => '',
        'website' => ''
    ];
    
    return $settings;
}

/**
 * Get SMTP configuration from database or fall back to constants
 */
function getSmtpConfig() {
    static $config = null;
    
    if ($config !== null) {
        return $config;
    }
    
    // Try to load from database
    try {
        require_once __DIR__ . '/config.php';
        $conn = getDbConnection();
        
        // Check if smtp_settings table exists and has active config
        $result = $conn->query("SELECT * FROM smtp_settings WHERE is_active = 1 LIMIT 1");
        
        if ($result && $result->num_rows > 0) {
            $dbConfig = $result->fetch_assoc();
            
            // Check if email notifications are enabled
            if (!$dbConfig['enable_email_notifications']) {
                $config = ['enabled' => false];
                return $config;
            }
            
            $config = [
                'enabled' => true,
                'host' => $dbConfig['smtp_host'],
                'port' => (int)$dbConfig['smtp_port'],
                'username' => $dbConfig['smtp_username'],
                'password' => $dbConfig['smtp_password'],
                'encryption' => $dbConfig['smtp_encryption'],
                'from_email' => $dbConfig['smtp_from_email'],
                'from_name' => $dbConfig['smtp_from_name'],
                'reply_to_email' => $dbConfig['smtp_reply_to_email'] ?? null,
                'reply_to_name' => $dbConfig['smtp_reply_to_name'] ?? null,
            ];
            return $config;
        }
    } catch (Exception $e) {
        error_log("Failed to load SMTP config from database: " . $e->getMessage());
    }
    
    // Fall back to constants if database config not available
    $config = [
        'enabled' => true,
        'host' => SMTP_HOST,
        'port' => SMTP_PORT,
        'username' => SMTP_USERNAME,
        'password' => SMTP_PASSWORD,
        'encryption' => SMTP_ENCRYPTION,
        'from_email' => SMTP_FROM_EMAIL,
        'from_name' => SMTP_FROM_NAME,
        'reply_to_email' => null,
        'reply_to_name' => null,
    ];
    
    return $config;
}

/**
 * Check if email notifications are enabled
 */
function isEmailEnabled() {
    $config = getSmtpConfig();
    return $config['enabled'] ?? true;
}

/**
 * Replace emoji characters in HTML email bodies with styled inline icon badges.
 * This ensures icons render reliably across all email clients (Gmail, Outlook, Yahoo, Apple Mail)
 * instead of appearing as garbled text like "âœ‰ï¸" or "ðŸ"¹".
 */
function processEmailEmojis($html) {
    // Each entry: emoji => [display_symbol, background_color]
    // Using BMP Unicode chars and ASCII that are 100% email-safe
    $emoji_map = [
        // Documents & Info
        '📋' => ['&#9776;', '#3b82f6'],   // ☰ list icon - blue
        '📄' => ['&#9776;', '#3b82f6'],   // ☰ document - blue
        '📂' => ['&#9776;', '#64748b'],   // ☰ folder - slate

        // Writing & Notes
        '📝' => ['&#9998;', '#6366f1'],   // ✎ pencil - indigo
        '✍️' => ['&#9998;', '#6366f1'],   // ✎ writing - indigo

        // Warnings & Alerts
        '⚠️' => ['!', '#f59e0b'],         // ! warning - amber
        '🚨' => ['!', '#ef4444'],         // ! urgent - red

        // Video & Media
        '📹' => ['&#9654;', '#8b5cf6'],   // ▶ video - purple
        '🎬' => ['&#9654;', '#8b5cf6'],   // ▶ recording - purple

        // Time & Schedule
        '⏰' => ['&#9200;', '#f59e0b'],   // ⏰ clock - amber
        '📅' => ['&#9635;', '#6366f1'],   // ▣ calendar - indigo

        // People & Accounts
        '👤' => ['&#9786;', '#6366f1'],   // ☺ person - indigo
        '👋' => ['&#9786;', '#3b82f6'],   // ☺ wave - blue

        // Grades & Charts
        '📊' => ['&#9632;', '#0ea5e9'],   // ■ chart - sky blue
        '🎯' => ['&#9678;', '#ef4444'],   // ◎ target - red

        // Finance & Money
        '💰' => ['$', '#10b981'],         // $ money - green
        '💳' => ['$', '#10b981'],         // $ credit card - green

        // Books & Education
        '📚' => ['&#9733;', '#8b5cf6'],   // ★ books - purple
        '📖' => ['&#9733;', '#6366f1'],   // ★ reading - indigo
        '🎓' => ['&#9733;', '#8b5cf6'],   // ★ graduation - purple

        // Success & Confirmation
        '✅' => ['&#10003;', '#22c55e'],  // ✓ check - green
        '🎉' => ['&#10003;', '#22c55e'],  // ✓ celebration - green

        // Errors & Cancellation
        '❌' => ['&#10007;', '#ef4444'],  // ✗ cross - red

        // Communication
        '✉️' => ['&#9993;', '#0891b2'],   // ✉ envelope - cyan
        '💬' => ['&#9993;', '#0891b2'],   // ✉ chat - cyan
        '💭' => ['&#9993;', '#6366f1'],   // ✉ thought - indigo
        '📢' => ['&#9654;', '#f59e0b'],   // ▶ announcement - amber

        // Notifications & System
        '🔔' => ['&#9830;', '#f59e0b'],   // ♦ bell - amber

        // Tips & Ideas
        '💡' => ['&#10022;', '#0ea5e9'],  // ✦ lightbulb - sky blue

        // Attachments
        '📎' => ['&#9776;', '#64748b'],   // ☰ attachment - slate
    ];

    foreach ($emoji_map as $emoji => [$symbol, $color]) {
        $icon_html = '<span style="display:inline-block;width:20px;height:20px;background:' . $color
            . ';color:#ffffff;border-radius:4px;text-align:center;font-size:12px;line-height:20px;'
            . 'margin-right:5px;vertical-align:middle;font-family:Arial,sans-serif;">'
            . $symbol . '</span>';
        $html = str_replace($emoji, $icon_html, $html);
    }

    return $html;
}

/**
 * Strip emoji characters from email subject lines for clean display.
 * Subjects cannot contain HTML, so emojis that garble must be removed.
 */
function cleanEmailSubject($subject) {
    // Remove emoji ranges (extended Unicode blocks)
    $patterns = [
        '/[\x{1F600}-\x{1F64F}]/u',  // Emoticons
        '/[\x{1F300}-\x{1F5FF}]/u',  // Misc Symbols & Pictographs
        '/[\x{1F680}-\x{1F6FF}]/u',  // Transport & Map Symbols
        '/[\x{1F700}-\x{1F77F}]/u',  // Alchemical Symbols
        '/[\x{1F900}-\x{1F9FF}]/u',  // Supplemental Symbols
        '/[\x{1FA00}-\x{1FAFF}]/u',  // Extended-A
        '/[\x{2600}-\x{26FF}]/u',    // Misc Symbols (⚠, ☀, etc.)
        '/[\x{2700}-\x{27BF}]/u',    // Dingbats (✉, ✎, ✓, ✗, etc.)
        '/[\x{23E9}-\x{23FA}]/u',    // Misc Technical (⏰, ⏩, etc.)
        '/[\x{FE0F}]/u',             // Variation Selectors
        '/[\x{200D}]/u',             // Zero-width joiner
    ];

    foreach ($patterns as $pattern) {
        $subject = preg_replace($pattern, '', $subject);
    }

    // Clean up any double spaces left behind
    return trim(preg_replace('/\s{2,}/', ' ', $subject));
}

/**
 * Generate professional email template wrapper
 */
function getEmailTemplate($title, $content, $footer_text = '', $primary_color = '#2563eb') {
    $uni = getUniversitySettings();
    $university_name = htmlspecialchars($uni['university_name'] ?? 'Virtual Learning Environment');
    $current_year = date('Y');
    
    return "
    <!DOCTYPE html>
    <html lang='en'>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <title>$title</title>
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body { 
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
                line-height: 1.6;
                color: #1f2937;
                background-color: #f3f4f6;
            }
            .email-wrapper { 
                max-width: 600px; 
                margin: 0 auto; 
                background-color: #ffffff;
            }
            .email-header { 
                background: linear-gradient(135deg, $primary_color 0%, #1e40af 100%);
                color: white; 
                padding: 30px 40px;
                text-align: center;
            }
            .email-header h1 {
                font-size: 24px;
                font-weight: 700;
                margin-bottom: 5px;
            }
            .email-header .subtitle {
                font-size: 14px;
                opacity: 0.9;
            }
            .email-body { 
                padding: 40px;
            }
            .greeting {
                font-size: 18px;
                color: #374151;
                margin-bottom: 20px;
            }
            .content-text {
                color: #4b5563;
                font-size: 15px;
                margin-bottom: 20px;
            }
            .info-box {
                background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
                border-left: 4px solid $primary_color;
                border-radius: 0 8px 8px 0;
                padding: 20px 25px;
                margin: 25px 0;
            }
            .info-box h3 {
                color: #1e293b;
                font-size: 16px;
                font-weight: 600;
                margin-bottom: 15px;
            }
            .info-row {
                display: flex;
                padding: 8px 0;
                border-bottom: 1px solid #e2e8f0;
            }
            .info-row:last-child {
                border-bottom: none;
            }
            .info-label {
                font-weight: 600;
                color: #64748b;
                width: 140px;
                font-size: 14px;
            }
            .info-value {
                color: #1e293b;
                font-weight: 500;
                font-size: 14px;
            }
            .credentials-box {
                background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
                border: 1px solid #f59e0b;
                border-radius: 8px;
                padding: 20px 25px;
                margin: 25px 0;
            }
            .credentials-box h3 {
                color: #92400e;
                font-size: 16px;
                font-weight: 600;
                margin-bottom: 15px;
            }
            .credential-item {
                background: white;
                border-radius: 6px;
                padding: 12px 15px;
                margin-bottom: 10px;
                display: flex;
                align-items: center;
            }
            .credential-item:last-child {
                margin-bottom: 0;
            }
            .credential-label {
                font-weight: 600;
                color: #78350f;
                width: 100px;
                font-size: 13px;
            }
            .credential-value {
                font-family: 'Consolas', 'Monaco', monospace;
                color: #1e293b;
                font-weight: 600;
                font-size: 15px;
                background: #f1f5f9;
                padding: 4px 12px;
                border-radius: 4px;
            }
            .alert-box {
                background: #fef2f2;
                border: 1px solid #fecaca;
                border-radius: 8px;
                padding: 15px 20px;
                margin: 20px 0;
                color: #991b1b;
                font-size: 13px;
            }
            .alert-box.warning {
                background: #fffbeb;
                border-color: #fde68a;
                color: #92400e;
            }
            .alert-box.success {
                background: #f0fdf4;
                border-color: #86efac;
                color: #166534;
            }
            .alert-box.info {
                background: #eff6ff;
                border-color: #bfdbfe;
                color: #1e40af;
            }
            .btn-primary {
                display: inline-block;
                background: linear-gradient(135deg, $primary_color 0%, #1e40af 100%);
                color: white !important;
                text-decoration: none;
                padding: 14px 32px;
                border-radius: 8px;
                font-weight: 600;
                font-size: 15px;
                text-align: center;
                margin: 20px 0;
                box-shadow: 0 4px 14px rgba(37, 99, 235, 0.3);
            }
            .btn-primary:hover {
                background: linear-gradient(135deg, #1d4ed8 0%, #1e3a8a 100%);
            }
            .btn-container {
                text-align: center;
                margin: 30px 0;
            }
            .divider {
                height: 1px;
                background: linear-gradient(90deg, transparent, #e5e7eb, transparent);
                margin: 30px 0;
            }
            .grade-display {
                text-align: center;
                padding: 30px;
                margin: 20px 0;
            }
            .grade-circle {
                width: 100px;
                height: 100px;
                border-radius: 50%;
                background: linear-gradient(135deg, $primary_color 0%, #1e40af 100%);
                display: inline-flex;
                align-items: center;
                justify-content: center;
                margin-bottom: 15px;
            }
            .grade-letter {
                color: white;
                font-size: 42px;
                font-weight: 700;
            }
            .grade-score {
                font-size: 28px;
                font-weight: 700;
                color: #1e293b;
            }
            .grade-status {
                font-size: 14px;
                font-weight: 600;
                padding: 6px 16px;
                border-radius: 20px;
                display: inline-block;
                margin-top: 10px;
            }
            .grade-pass {
                background: #dcfce7;
                color: #166534;
            }
            .grade-fail {
                background: #fef2f2;
                color: #991b1b;
            }
            .email-footer {
                background: #f8fafc;
                padding: 30px 40px;
                text-align: center;
                border-top: 1px solid #e5e7eb;
            }
            .footer-logo {
                font-weight: 700;
                font-size: 18px;
                color: $primary_color;
                margin-bottom: 15px;
            }
            .footer-text {
                color: #64748b;
                font-size: 13px;
                line-height: 1.8;
            }
            .footer-links {
                margin: 15px 0;
            }
            .footer-links a {
                color: $primary_color;
                text-decoration: none;
                margin: 0 10px;
                font-size: 13px;
            }
            .footer-copyright {
                color: #94a3b8;
                font-size: 12px;
                margin-top: 15px;
            }
            .highlight { 
                color: $primary_color; 
                font-weight: 600; 
            }
            .steps-list {
                margin: 20px 0;
                padding-left: 0;
                list-style: none;
            }
            .steps-list li {
                padding: 12px 0 12px 35px;
                position: relative;
                border-bottom: 1px solid #f1f5f9;
            }
            .steps-list li:before {
                content: counter(step-counter);
                counter-increment: step-counter;
                position: absolute;
                left: 0;
                width: 24px;
                height: 24px;
                background: $primary_color;
                color: white;
                border-radius: 50%;
                font-size: 12px;
                font-weight: 600;
                display: flex;
                align-items: center;
                justify-content: center;
            }
            .steps-list {
                counter-reset: step-counter;
            }
            @media only screen and (max-width: 600px) {
                .email-wrapper { width: 100% !important; }
                .email-header, .email-body, .email-footer { padding: 25px 20px; }
                .info-row { flex-direction: column; }
                .info-label { width: 100%; margin-bottom: 4px; }
                .credential-item { flex-direction: column; text-align: center; }
                .credential-label { width: 100%; margin-bottom: 8px; }
            }
        </style>
    </head>
    <body>
        <div class='email-wrapper'>
            <div class='email-header'>
                <h1>$university_name</h1>
                <p class='subtitle'>Virtual Learning Environment</p>
            </div>
            <div class='email-body'>
                $content
            </div>
            <div class='email-footer'>
                <div class='footer-logo'>$university_name</div>
                <p class='footer-text'>
                    " . ($footer_text ?: "This is an automated notification from the VLE System.<br>Please do not reply directly to this email.") . "
                </p>
                <div class='footer-copyright'>
                    &copy; $current_year $university_name. All rights reserved.
                </div>
            </div>
        </div>
    </body>
    </html>";
}

/**
 * Send email using PHPMailer with database or fallback configuration
 */
function sendEmail($to_email, $to_name, $subject, $body, $cc_email = null, $cc_name = null) {
    // Load SMTP configuration from database or fallback
    $smtpConfig = getSmtpConfig();
    
    // Check if emails are disabled
    if (!$smtpConfig['enabled']) {
        error_log("Email not sent - notifications are disabled: $subject to $to_email");
        return false;
    }
    
    require_once __DIR__ . '/../vendor/autoload.php';
    
    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    
    try {
        // Server settings from config
        $mail->isSMTP();
        $mail->Host = $smtpConfig['host'];
        $mail->SMTPAuth = true;
        $mail->Username = $smtpConfig['username'];
        $mail->Password = $smtpConfig['password'];
        $mail->SMTPSecure = $smtpConfig['encryption'] === 'none' ? false : $smtpConfig['encryption'];
        $mail->Port = $smtpConfig['port'];
        
        // Recipients
        $mail->setFrom($smtpConfig['from_email'], $smtpConfig['from_name']);
        $mail->addAddress($to_email, $to_name);
        
        // Reply-To if configured
        if (!empty($smtpConfig['reply_to_email'])) {
            $mail->addReplyTo($smtpConfig['reply_to_email'], $smtpConfig['reply_to_name'] ?: $smtpConfig['from_name']);
        }
        
        // CC if provided
        if ($cc_email) {
            $mail->addCC($cc_email, $cc_name);
        }
        
        // Content
        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';
        $mail->Subject = cleanEmailSubject($subject);
        $mail->Body = processEmailEmojis($body);
        $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $body));
        
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Email sending failed: " . $mail->ErrorInfo);
        return false;
    }
}

// ============================================================================
// ACCOUNT & AUTHENTICATION NOTIFICATIONS
// ============================================================================

/**
 * Send welcome email to new student with login credentials
 */
function sendStudentWelcomeEmail($student_email, $student_name, $student_id, $username, $temp_password, $program = '', $campus = '') {
    $uni = getUniversitySettings();
    $subject = "Welcome to " . ($uni['university_name'] ?? 'VLE System') . " - Your Account is Ready";
    
    $content = "
        <p class='greeting'>Dear <strong>" . htmlspecialchars($student_name) . "</strong>,</p>
        
        <p class='content-text'>
            Welcome to our Virtual Learning Environment! Your student account has been successfully created and you are now ready to begin your academic journey with us.
        </p>
        
        <div class='info-box'>
            <h3>📋 Student Information</h3>
            <div class='info-row'>
                <span class='info-label'>Student ID</span>
                <span class='info-value'>" . htmlspecialchars($student_id) . "</span>
            </div>
            <div class='info-row'>
                <span class='info-label'>Full Name</span>
                <span class='info-value'>" . htmlspecialchars($student_name) . "</span>
            </div>
            " . ($program ? "<div class='info-row'>
                <span class='info-label'>Program</span>
                <span class='info-value'>" . htmlspecialchars($program) . "</span>
            </div>" : "") . "
            " . ($campus ? "<div class='info-row'>
                <span class='info-label'>Campus</span>
                <span class='info-value'>" . htmlspecialchars($campus) . "</span>
            </div>" : "") . "
        </div>
        
        <div class='credentials-box'>
            <h3>🔐 Your Login Credentials</h3>
            <div class='credential-item'>
                <span class='credential-label'>Username</span>
                <span class='credential-value'>" . htmlspecialchars($username) . "</span>
            </div>
            <div class='credential-item'>
                <span class='credential-label'>Password</span>
                <span class='credential-value'>" . htmlspecialchars($temp_password) . "</span>
            </div>
        </div>
        
        <div class='alert-box warning'>
            <strong>⚠️ Security Notice:</strong> For your security, you will be required to change your password upon first login. Please choose a strong, unique password.
        </div>
        
        <div class='btn-container'>
            <a href='" . SYSTEM_URL . "/login.php' class='btn-primary'>Login to VLE Portal</a>
        </div>
        
        <div class='divider'></div>
        
        <p class='content-text'><strong>Getting Started:</strong></p>
        <ol class='steps-list'>
            <li>Login using the credentials provided above</li>
            <li>Change your temporary password to a secure one</li>
            <li>Complete your profile information</li>
            <li>Browse available courses and register</li>
            <li>Start learning!</li>
        </ol>
        
        <p class='content-text'>
            If you have any questions or need assistance, please contact our support team or visit the help center.
        </p>
    ";
    
    $body = getEmailTemplate("Welcome to VLE", $content);
    return sendEmail($student_email, $student_name, $subject, $body);
}

/**
 * Send welcome email to new lecturer with login credentials
 */
function sendLecturerWelcomeEmail($lecturer_email, $lecturer_name, $username, $temp_password, $department = '', $position = '') {
    $uni = getUniversitySettings();
    $subject = "Welcome to " . ($uni['university_name'] ?? 'VLE System') . " - Lecturer Account Created";
    
    $content = "
        <p class='greeting'>Dear <strong>" . htmlspecialchars($lecturer_name) . "</strong>,</p>
        
        <p class='content-text'>
            Welcome to our Virtual Learning Environment! Your lecturer account has been successfully created. You now have access to our comprehensive teaching and course management tools.
        </p>
        
        <div class='info-box'>
            <h3>👤 Account Information</h3>
            <div class='info-row'>
                <span class='info-label'>Full Name</span>
                <span class='info-value'>" . htmlspecialchars($lecturer_name) . "</span>
            </div>
            " . ($department ? "<div class='info-row'>
                <span class='info-label'>Department</span>
                <span class='info-value'>" . htmlspecialchars($department) . "</span>
            </div>" : "") . "
            " . ($position ? "<div class='info-row'>
                <span class='info-label'>Position</span>
                <span class='info-value'>" . htmlspecialchars($position) . "</span>
            </div>" : "") . "
        </div>
        
        <div class='credentials-box'>
            <h3>🔐 Your Login Credentials</h3>
            <div class='credential-item'>
                <span class='credential-label'>Username</span>
                <span class='credential-value'>" . htmlspecialchars($username) . "</span>
            </div>
            <div class='credential-item'>
                <span class='credential-label'>Password</span>
                <span class='credential-value'>" . htmlspecialchars($temp_password) . "</span>
            </div>
        </div>
        
        <div class='alert-box warning'>
            <strong>⚠️ Security Notice:</strong> For security purposes, please change your password immediately after your first login.
        </div>
        
        <div class='btn-container'>
            <a href='" . SYSTEM_URL . "/login.php' class='btn-primary'>Access Lecturer Portal</a>
        </div>
        
        <div class='divider'></div>
        
        <p class='content-text'><strong>As a Lecturer, You Can:</strong></p>
        <ol class='steps-list'>
            <li>Manage your assigned courses and modules</li>
            <li>Upload course materials and resources</li>
            <li>Create and manage assignments</li>
            <li>Grade student submissions</li>
            <li>Schedule and host live sessions</li>
            <li>Communicate with students</li>
        </ol>
    ";
    
    $body = getEmailTemplate("Welcome - Lecturer Account", $content, '', '#7c3aed');
    return sendEmail($lecturer_email, $lecturer_name, $subject, $body);
}

/**
 * Send welcome email to new finance user
 */
function sendFinanceWelcomeEmail($email, $name, $username, $temp_password, $position = '') {
    $uni = getUniversitySettings();
    $subject = "Welcome to " . ($uni['university_name'] ?? 'VLE System') . " - Finance Account Created";
    
    $content = "
        <p class='greeting'>Dear <strong>" . htmlspecialchars($name) . "</strong>,</p>
        
        <p class='content-text'>
            Your Finance Department account has been successfully created. You now have access to the financial management features of our Virtual Learning Environment.
        </p>
        
        <div class='info-box'>
            <h3>👤 Account Information</h3>
            <div class='info-row'>
                <span class='info-label'>Full Name</span>
                <span class='info-value'>" . htmlspecialchars($name) . "</span>
            </div>
            <div class='info-row'>
                <span class='info-label'>Department</span>
                <span class='info-value'>Finance Department</span>
            </div>
            " . ($position ? "<div class='info-row'>
                <span class='info-label'>Position</span>
                <span class='info-value'>" . htmlspecialchars($position) . "</span>
            </div>" : "") . "
        </div>
        
        <div class='credentials-box'>
            <h3>🔐 Your Login Credentials</h3>
            <div class='credential-item'>
                <span class='credential-label'>Username</span>
                <span class='credential-value'>" . htmlspecialchars($username) . "</span>
            </div>
            <div class='credential-item'>
                <span class='credential-label'>Password</span>
                <span class='credential-value'>" . htmlspecialchars($temp_password) . "</span>
            </div>
        </div>
        
        <div class='alert-box warning'>
            <strong>⚠️ Important:</strong> Please change your password upon first login for security purposes.
        </div>
        
        <div class='btn-container'>
            <a href='" . SYSTEM_URL . "/login.php' class='btn-primary'>Access Finance Portal</a>
        </div>
    ";
    
    $body = getEmailTemplate("Finance Account Created", $content, '', '#059669');
    return sendEmail($email, $name, $subject, $body);
}

/**
 * Send welcome email to new administrator
 */
function sendAdminWelcomeEmail($email, $name, $username, $temp_password) {
    $uni = getUniversitySettings();
    $subject = "Welcome to " . ($uni['university_name'] ?? 'VLE System') . " - Administrator Account Created";
    
    $content = "
        <p class='greeting'>Dear <strong>" . htmlspecialchars($name) . "</strong>,</p>
        
        <p class='content-text'>
            Your Administrator account has been successfully created. You now have full administrative access to our Virtual Learning Environment system.
        </p>
        
        <div class='credentials-box'>
            <h3>🔐 Your Login Credentials</h3>
            <div class='credential-item'>
                <span class='credential-label'>Username</span>
                <span class='credential-value'>" . htmlspecialchars($username) . "</span>
            </div>
            <div class='credential-item'>
                <span class='credential-label'>Password</span>
                <span class='credential-value'>" . htmlspecialchars($temp_password) . "</span>
            </div>
        </div>
        
        <div class='alert-box'>
            <strong>🔒 Security Notice:</strong> As an administrator, you have elevated privileges. Please ensure you:
            <ul style='margin-top: 10px; padding-left: 20px;'>
                <li>Change your password immediately</li>
                <li>Use a strong, unique password</li>
                <li>Never share your credentials</li>
                <li>Log out when not using the system</li>
            </ul>
        </div>
        
        <div class='btn-container'>
            <a href='" . SYSTEM_URL . "/login.php' class='btn-primary'>Access Admin Portal</a>
        </div>
    ";
    
    $body = getEmailTemplate("Administrator Account", $content, '', '#dc2626');
    return sendEmail($email, $name, $subject, $body);
}

/**
 * Send password reset confirmation email
 */
function sendPasswordResetEmail($email, $name, $new_password, $reset_by_admin = true) {
    $uni = getUniversitySettings();
    $subject = "Password Reset - " . ($uni['university_name'] ?? 'VLE System');
    
    $content = "
        <p class='greeting'>Dear <strong>" . htmlspecialchars($name) . "</strong>,</p>
        
        <p class='content-text'>
            " . ($reset_by_admin ? "Your password has been reset by an administrator." : "Your password has been successfully reset.") . "
        </p>
        
        <div class='credentials-box'>
            <h3>🔐 New Login Credentials</h3>
            <div class='credential-item'>
                <span class='credential-label'>New Password</span>
                <span class='credential-value'>" . htmlspecialchars($new_password) . "</span>
            </div>
        </div>
        
        <div class='alert-box warning'>
            <strong>⚠️ Important:</strong> For your security, please change this password immediately after logging in.
        </div>
        
        <div class='btn-container'>
            <a href='" . SYSTEM_URL . "/login.php' class='btn-primary'>Login Now</a>
        </div>
        
        <div class='alert-box info'>
            <strong>ℹ️ Note:</strong> If you did not request this password reset, please contact the system administrator immediately.
        </div>
    ";
    
    $body = getEmailTemplate("Password Reset", $content);
    return sendEmail($email, $name, $subject, $body);
}

/**
 * Send password change confirmation
 */
function sendPasswordChangedEmail($email, $name) {
    $uni = getUniversitySettings();
    $subject = "Password Changed Successfully - " . ($uni['university_name'] ?? 'VLE System');
    
    $content = "
        <p class='greeting'>Dear <strong>" . htmlspecialchars($name) . "</strong>,</p>
        
        <div class='alert-box success'>
            <strong>✅ Success!</strong> Your password has been changed successfully.
        </div>
        
        <p class='content-text'>
            This email confirms that your VLE account password was changed on <strong>" . date('F j, Y \a\t g:i A') . "</strong>.
        </p>
        
        <div class='alert-box'>
            <strong>🔒 Security Alert:</strong> If you did not make this change, please:
            <ol style='margin-top: 10px; padding-left: 20px;'>
                <li>Contact the administrator immediately</li>
                <li>Request a password reset</li>
                <li>Review your account activity</li>
            </ol>
        </div>
    ";
    
    $body = getEmailTemplate("Password Changed", $content);
    return sendEmail($email, $name, $subject, $body);
}

// ============================================================================
// ENROLLMENT & COURSE NOTIFICATIONS
// ============================================================================

/**
 * Send course enrollment approval notification
 */
function sendEnrollmentApprovedEmail($student_email, $student_name, $course_name, $course_code, $semester, $academic_year) {
    $uni = getUniversitySettings();
    $subject = "Course Registration Approved - " . htmlspecialchars($course_code);
    
    $content = "
        <p class='greeting'>Dear <strong>" . htmlspecialchars($student_name) . "</strong>,</p>
        
        <div class='alert-box success'>
            <strong>🎉 Congratulations!</strong> Your course registration has been approved.
        </div>
        
        <div class='info-box'>
            <h3>📚 Course Details</h3>
            <div class='info-row'>
                <span class='info-label'>Course Code</span>
                <span class='info-value'>" . htmlspecialchars($course_code) . "</span>
            </div>
            <div class='info-row'>
                <span class='info-label'>Course Name</span>
                <span class='info-value'>" . htmlspecialchars($course_name) . "</span>
            </div>
            <div class='info-row'>
                <span class='info-label'>Semester</span>
                <span class='info-value'>" . htmlspecialchars($semester) . "</span>
            </div>
            <div class='info-row'>
                <span class='info-label'>Academic Year</span>
                <span class='info-value'>" . htmlspecialchars($academic_year) . "</span>
            </div>
            <div class='info-row'>
                <span class='info-label'>Approved On</span>
                <span class='info-value'>" . date('F j, Y') . "</span>
            </div>
        </div>
        
        <p class='content-text'>
            You now have access to all course materials, assignments, and resources. We encourage you to:
        </p>
        
        <ol class='steps-list'>
            <li>Review the course syllabus and materials</li>
            <li>Check assignment deadlines</li>
            <li>Participate in course discussions</li>
            <li>Attend scheduled live sessions</li>
        </ol>
        
        <div class='btn-container'>
            <a href='" . SYSTEM_URL . "/student/dashboard.php' class='btn-primary'>Go to My Courses</a>
        </div>
    ";
    
    $body = getEmailTemplate("Enrollment Approved", $content, '', '#059669');
    return sendEmail($student_email, $student_name, $subject, $body);
}

/**
 * Send course enrollment rejection notification
 */
function sendEnrollmentRejectedEmail($student_email, $student_name, $course_name, $course_code, $reason = '') {
    $uni = getUniversitySettings();
    $subject = "Course Registration Update - " . htmlspecialchars($course_code);
    
    $content = "
        <p class='greeting'>Dear <strong>" . htmlspecialchars($student_name) . "</strong>,</p>
        
        <p class='content-text'>
            We regret to inform you that your registration request for the following course could not be approved at this time.
        </p>
        
        <div class='info-box'>
            <h3>📋 Request Details</h3>
            <div class='info-row'>
                <span class='info-label'>Course Code</span>
                <span class='info-value'>" . htmlspecialchars($course_code) . "</span>
            </div>
            <div class='info-row'>
                <span class='info-label'>Course Name</span>
                <span class='info-value'>" . htmlspecialchars($course_name) . "</span>
            </div>
            <div class='info-row'>
                <span class='info-label'>Status</span>
                <span class='info-value' style='color: #dc2626;'>Not Approved</span>
            </div>
        </div>
        
        " . ($reason ? "<div class='alert-box info'>
            <strong>📝 Reason:</strong> " . htmlspecialchars($reason) . "
        </div>" : "") . "
        
        <p class='content-text'>
            If you believe this decision was made in error or have questions, please contact the academic office or your department administrator.
        </p>
        
        <div class='btn-container'>
            <a href='" . SYSTEM_URL . "/student/dashboard.php' class='btn-primary'>View Available Courses</a>
        </div>
    ";
    
    $body = getEmailTemplate("Registration Update", $content);
    return sendEmail($student_email, $student_name, $subject, $body);
}

// ============================================================================
// ASSIGNMENT & GRADE NOTIFICATIONS
// ============================================================================

/**
 * Send assignment submission notification to lecturer
 */
function sendAssignmentSubmissionEmail($student_email, $student_name, $lecturer_email, $lecturer_name, $assignment_title, $course_name, $submission_id) {
    $subject = "New Submission: " . htmlspecialchars($assignment_title);
    
    $content = "
        <p class='greeting'>Dear <strong>" . htmlspecialchars($lecturer_name) . "</strong>,</p>
        
        <p class='content-text'>
            A student has submitted an assignment for your review.
        </p>
        
        <div class='info-box'>
            <h3>📝 Submission Details</h3>
            <div class='info-row'>
                <span class='info-label'>Student</span>
                <span class='info-value'>" . htmlspecialchars($student_name) . "</span>
            </div>
            <div class='info-row'>
                <span class='info-label'>Email</span>
                <span class='info-value'>" . htmlspecialchars($student_email) . "</span>
            </div>
            <div class='info-row'>
                <span class='info-label'>Course</span>
                <span class='info-value'>" . htmlspecialchars($course_name) . "</span>
            </div>
            <div class='info-row'>
                <span class='info-label'>Assignment</span>
                <span class='info-value'>" . htmlspecialchars($assignment_title) . "</span>
            </div>
            <div class='info-row'>
                <span class='info-label'>Submitted</span>
                <span class='info-value'>" . date('F j, Y \a\t g:i A') . "</span>
            </div>
        </div>
        
        <div class='btn-container'>
            <a href='" . SYSTEM_URL . "/lecturer/gradebook.php?submission_id=" . (int)$submission_id . "' class='btn-primary'>Review & Grade Submission</a>
        </div>
    ";
    
    $body = getEmailTemplate("New Assignment Submission", $content, '', '#7c3aed');
    return sendEmail($lecturer_email, $lecturer_name, $subject, $body, $student_email, $student_name);
}

/**
 * Send grade notification to student
 */
function sendGradeNotificationEmail($student_email, $student_name, $lecturer_email, $lecturer_name, $assignment_title, $course_name, $score, $grade_letter, $feedback, $course_id) {
    $subject = "Assignment Graded: " . htmlspecialchars($assignment_title);
    
    $status_class = $score >= 50 ? 'grade-pass' : 'grade-fail';
    $status_text = $score >= 50 ? 'PASSED' : 'NEEDS IMPROVEMENT';
    $primary_color = $score >= 50 ? '#059669' : '#dc2626';
    
    $content = "
        <p class='greeting'>Dear <strong>" . htmlspecialchars($student_name) . "</strong>,</p>
        
        <p class='content-text'>
            Your assignment has been graded by <strong>" . htmlspecialchars($lecturer_name) . "</strong>.
        </p>
        
        <div class='info-box'>
            <h3>📋 Assignment Details</h3>
            <div class='info-row'>
                <span class='info-label'>Course</span>
                <span class='info-value'>" . htmlspecialchars($course_name) . "</span>
            </div>
            <div class='info-row'>
                <span class='info-label'>Assignment</span>
                <span class='info-value'>" . htmlspecialchars($assignment_title) . "</span>
            </div>
        </div>
        
        <div class='grade-display'>
            <div class='grade-circle' style='background: linear-gradient(135deg, $primary_color 0%, " . ($score >= 50 ? '#047857' : '#b91c1c') . " 100%);'>
                <span class='grade-letter'>" . htmlspecialchars($grade_letter) . "</span>
            </div>
            <div class='grade-score'>" . number_format($score, 1) . "%</div>
            <div class='grade-status $status_class'>$status_text</div>
        </div>
        
        " . ($feedback ? "
        <div class='info-box'>
            <h3>💬 Instructor Feedback</h3>
            <p style='color: #374151; line-height: 1.8;'>" . nl2br(htmlspecialchars($feedback)) . "</p>
        </div>
        " : "") . "
        
        <div class='btn-container'>
            <a href='" . SYSTEM_URL . "/student/dashboard.php?course_id=" . (int)$course_id . "&view=grades' class='btn-primary'>View All Grades</a>
        </div>
    ";
    
    $body = getEmailTemplate("Assignment Graded", $content, '', $primary_color);
    return sendEmail($student_email, $student_name, $subject, $body, $lecturer_email, $lecturer_name);
}

/**
 * Send new assignment notification to students
 */
function sendNewAssignmentEmail($student_email, $student_name, $lecturer_name, $course_name, $assignment_title, $due_date, $course_id) {
    $subject = "New Assignment: " . htmlspecialchars($assignment_title);
    
    $content = "
        <p class='greeting'>Dear <strong>" . htmlspecialchars($student_name) . "</strong>,</p>
        
        <p class='content-text'>
            A new assignment has been posted in your course by <strong>" . htmlspecialchars($lecturer_name) . "</strong>.
        </p>
        
        <div class='info-box'>
            <h3>📝 Assignment Details</h3>
            <div class='info-row'>
                <span class='info-label'>Course</span>
                <span class='info-value'>" . htmlspecialchars($course_name) . "</span>
            </div>
            <div class='info-row'>
                <span class='info-label'>Assignment</span>
                <span class='info-value'>" . htmlspecialchars($assignment_title) . "</span>
            </div>
            <div class='info-row'>
                <span class='info-label'>Due Date</span>
                <span class='info-value' style='color: #dc2626; font-weight: 600;'>" . date('F j, Y \a\t g:i A', strtotime($due_date)) . "</span>
            </div>
        </div>
        
        <div class='alert-box warning'>
            <strong>⏰ Reminder:</strong> Please ensure you submit your assignment before the deadline to avoid any penalties.
        </div>
        
        <div class='btn-container'>
            <a href='" . SYSTEM_URL . "/student/dashboard.php?course_id=" . (int)$course_id . "' class='btn-primary'>View Assignment</a>
        </div>
    ";
    
    $body = getEmailTemplate("New Assignment Posted", $content);
    return sendEmail($student_email, $student_name, $subject, $body);
}

// ============================================================================
// MESSAGE & ANNOUNCEMENT NOTIFICATIONS
// ============================================================================

/**
 * Send message notification
 */
function sendMessageNotificationEmail($recipient_email, $recipient_name, $sender_email, $sender_name, $subject_text, $message_content, $message_id, $recipient_type) {
    $subject = "New Message: " . htmlspecialchars($subject_text);
    
    $view_url = SYSTEM_URL . "/$recipient_type/messages.php?message_id=$message_id";
    $preview = substr(strip_tags($message_content), 0, 200);
    if (strlen($message_content) > 200) $preview .= '...';
    
    $content = "
        <p class='greeting'>Dear <strong>" . htmlspecialchars($recipient_name) . "</strong>,</p>
        
        <p class='content-text'>
            You have received a new message from <strong>" . htmlspecialchars($sender_name) . "</strong>.
        </p>
        
        <div class='info-box'>
            <h3>✉️ Message Preview</h3>
            <div class='info-row'>
                <span class='info-label'>From</span>
                <span class='info-value'>" . htmlspecialchars($sender_name) . "</span>
            </div>
            <div class='info-row'>
                <span class='info-label'>Subject</span>
                <span class='info-value'>" . htmlspecialchars($subject_text) . "</span>
            </div>
            <div class='info-row'>
                <span class='info-label'>Received</span>
                <span class='info-value'>" . date('F j, Y \a\t g:i A') . "</span>
            </div>
        </div>
        
        <div style='background: #f8fafc; border-radius: 8px; padding: 20px; margin: 20px 0; border: 1px solid #e2e8f0;'>
            <p style='color: #475569; font-style: italic; margin: 0;'>\"" . htmlspecialchars($preview) . "\"</p>
        </div>
        
        <div class='btn-container'>
            <a href='$view_url' class='btn-primary'>Read Full Message</a>
        </div>
    ";
    
    $body = getEmailTemplate("New Message", $content, '', '#0891b2');
    return sendEmail($recipient_email, $recipient_name, $subject, $body, $sender_email, $sender_name);
}

/**
 * Send course announcement notification
 */
function sendAnnouncementEmail($student_email, $student_name, $lecturer_name, $course_name, $announcement_title, $announcement_content, $course_id) {
    $subject = "📢 Course Announcement: " . htmlspecialchars($course_name);
    
    $content = "
        <p class='greeting'>Dear <strong>" . htmlspecialchars($student_name) . "</strong>,</p>
        
        <p class='content-text'>
            <strong>" . htmlspecialchars($lecturer_name) . "</strong> has posted an announcement for <strong>" . htmlspecialchars($course_name) . "</strong>.
        </p>
        
        <div class='info-box' style='border-left-color: #f59e0b;'>
            <h3 style='color: #92400e;'>📢 " . htmlspecialchars($announcement_title) . "</h3>
            <p style='color: #374151; line-height: 1.8; margin-top: 15px;'>" . nl2br(htmlspecialchars($announcement_content)) . "</p>
        </div>
        
        <div class='btn-container'>
            <a href='" . SYSTEM_URL . "/student/dashboard.php?course_id=" . (int)$course_id . "' class='btn-primary'>View Course</a>
        </div>
    ";
    
    $body = getEmailTemplate("Course Announcement", $content, '', '#f59e0b');
    return sendEmail($student_email, $student_name, $subject, $body);
}

// ============================================================================
// PAYMENT & FINANCE NOTIFICATIONS
// ============================================================================

/**
 * Send payment received notification
 */
function sendPaymentReceivedEmail($student_email, $student_name, $amount, $payment_method, $reference_number, $new_balance, $payment_percentage) {
    $uni = getUniversitySettings();
    $subject = "Payment Received - " . ($uni['university_name'] ?? 'VLE System');
    
    $content = "
        <p class='greeting'>Dear <strong>" . htmlspecialchars($student_name) . "</strong>,</p>
        
        <div class='alert-box success'>
            <strong>✅ Payment Received!</strong> We have successfully received your payment.
        </div>
        
        <div class='info-box'>
            <h3>💳 Payment Details</h3>
            <div class='info-row'>
                <span class='info-label'>Amount Paid</span>
                <span class='info-value' style='color: #059669; font-weight: 700;'>K" . number_format($amount, 2) . "</span>
            </div>
            <div class='info-row'>
                <span class='info-label'>Payment Method</span>
                <span class='info-value'>" . htmlspecialchars($payment_method) . "</span>
            </div>
            <div class='info-row'>
                <span class='info-label'>Reference #</span>
                <span class='info-value'>" . htmlspecialchars($reference_number) . "</span>
            </div>
            <div class='info-row'>
                <span class='info-label'>Date</span>
                <span class='info-value'>" . date('F j, Y \a\t g:i A') . "</span>
            </div>
        </div>
        
        <div class='info-box'>
            <h3>📊 Account Summary</h3>
            <div class='info-row'>
                <span class='info-label'>Remaining Balance</span>
                <span class='info-value'>K" . number_format($new_balance, 2) . "</span>
            </div>
            <div class='info-row'>
                <span class='info-label'>Payment Progress</span>
                <span class='info-value'>" . number_format($payment_percentage, 1) . "%</span>
            </div>
        </div>
        
        <p class='content-text'>
            Thank you for your payment. If you have any questions regarding your account, please contact the Finance Department.
        </p>
        
        <div class='btn-container'>
            <a href='" . SYSTEM_URL . "/student/dashboard.php' class='btn-primary'>View My Account</a>
        </div>
    ";
    
    $body = getEmailTemplate("Payment Confirmation", $content, '', '#059669');
    return sendEmail($student_email, $student_name, $subject, $body);
}

/**
 * Send payment approved email with receipt link
 * This is sent when finance approves a student's payment submission
 */
function sendPaymentApprovedWithReceiptEmail($student_email, $student_name, $amount, $payment_method, $reference_number, $new_balance, $payment_percentage, $submission_id) {
    $uni = getUniversitySettings();
    $subject = "Payment Approved - " . ($uni['university_name'] ?? 'VLE System');
    $receipt_url = SYSTEM_URL . "/finance/payment_receipt.php?id=" . (int)$submission_id;
    
    // Determine access level based on payment percentage
    $access_info = '';
    if ($payment_percentage >= 100) {
        $access_level = 'Full Access (All 52 weeks)';
        $access_color = '#059669';
    } elseif ($payment_percentage >= 75) {
        $access_level = '13 Weeks Access';
        $access_color = '#2563eb';
    } elseif ($payment_percentage >= 50) {
        $access_level = '9 Weeks Access';
        $access_color = '#f59e0b';
    } elseif ($payment_percentage >= 25) {
        $access_level = '4 Weeks Access';
        $access_color = '#f59e0b';
    } else {
        $access_level = 'Limited Access';
        $access_color = '#dc2626';
    }
    
    $content = "
        <p class='greeting'>Dear <strong>" . htmlspecialchars($student_name) . "</strong>,</p>
        
        <div class='alert-box success'>
            <strong>✅ Payment Approved!</strong> Your payment has been verified and approved by the Finance Department.
        </div>
        
        <div class='info-box'>
            <h3>💳 Payment Details</h3>
            <div class='info-row'>
                <span class='info-label'>Amount Paid</span>
                <span class='info-value' style='color: #059669; font-weight: 700; font-size: 16px;'>K" . number_format($amount, 2) . "</span>
            </div>
            <div class='info-row'>
                <span class='info-label'>Payment Method</span>
                <span class='info-value'>" . htmlspecialchars($payment_method) . "</span>
            </div>
            <div class='info-row'>
                <span class='info-label'>Reference #</span>
                <span class='info-value'>" . htmlspecialchars($reference_number) . "</span>
            </div>
            <div class='info-row'>
                <span class='info-label'>Approved On</span>
                <span class='info-value'>" . date('F j, Y \a\t g:i A') . "</span>
            </div>
        </div>
        
        <div class='info-box'>
            <h3>📊 Account Summary</h3>
            <div class='info-row'>
                <span class='info-label'>Remaining Balance</span>
                <span class='info-value' style='font-weight: 700;'>K" . number_format($new_balance, 2) . "</span>
            </div>
            <div class='info-row'>
                <span class='info-label'>Payment Progress</span>
                <span class='info-value'>" . number_format($payment_percentage, 1) . "%</span>
            </div>
            <div class='info-row'>
                <span class='info-label'>Content Access</span>
                <span class='info-value' style='color: $access_color; font-weight: 600;'>$access_level</span>
            </div>
        </div>

        <div class='btn-container'>
            <a href='$receipt_url' class='btn-primary' style='margin-right: 10px;'>View & Print Receipt</a>
            <a href='" . SYSTEM_URL . "/student/payment_history.php' class='btn-secondary'>Payment History</a>
        </div>
        
        <p class='content-text' style='margin-top: 20px; font-size: 13px; color: #64748b;'>
            Please keep this email as proof of payment. You can view and print your official receipt at any time using the button above.
        </p>
    ";
    
    $body = getEmailTemplate("Payment Approved", $content, '', '#059669');
    return sendEmail($student_email, $student_name, $subject, $body);
}

/**
 * Send payment reminder notification
 */
function sendPaymentReminderEmail($student_email, $student_name, $balance, $due_date = '') {
    $uni = getUniversitySettings();
    $subject = "Payment Reminder - " . ($uni['university_name'] ?? 'VLE System');
    
    $content = "
        <p class='greeting'>Dear <strong>" . htmlspecialchars($student_name) . "</strong>,</p>
        
        <p class='content-text'>
            This is a friendly reminder about your outstanding fees.
        </p>
        
        <div class='info-box'>
            <h3>💰 Account Summary</h3>
            <div class='info-row'>
                <span class='info-label'>Outstanding Balance</span>
                <span class='info-value' style='color: #dc2626; font-weight: 700; font-size: 18px;'>K" . number_format($balance, 2) . "</span>
            </div>
            " . ($due_date ? "<div class='info-row'>
                <span class='info-label'>Due Date</span>
                <span class='info-value' style='color: #dc2626;'>" . date('F j, Y', strtotime($due_date)) . "</span>
            </div>" : "") . "
        </div>
        
        <div class='alert-box warning'>
            <strong>⚠️ Important:</strong> To maintain full access to course materials and avoid any service interruptions, please ensure your fees are paid on time.
        </div>
        
        <p class='content-text'>
            If you have already made a payment, please disregard this notice. For payment options or to discuss a payment plan, please contact the Finance Department.
        </p>
        
        <div class='btn-container'>
            <a href='" . SYSTEM_URL . "/student/dashboard.php' class='btn-primary'>Make a Payment</a>
        </div>
    ";
    
    $body = getEmailTemplate("Payment Reminder", $content, '', '#dc2626');
    return sendEmail($student_email, $student_name, $subject, $body);
}

// ============================================================================
// LIVE SESSION NOTIFICATIONS
// ============================================================================

/**
 * Send live session invitation
 */
function sendLiveSessionInviteEmail($student_email, $student_name, $lecturer_name, $course_name, $session_title, $session_date, $session_time, $meeting_link, $meeting_password = '', $invites_page_link = '') {
    $subject = "📹 Live Session: " . htmlspecialchars($session_title);
    
    $content = "
        <p class='greeting'>Dear <strong>" . htmlspecialchars($student_name) . "</strong>,</p>
        
        <p class='content-text'>
            You are invited to attend a <strong>LIVE</strong> session for <strong>" . htmlspecialchars($course_name) . "</strong>. Your lecturer is waiting — join now!
        </p>
        
        <div style='text-align:center; margin: 25px 0;'>
            <a href='" . htmlspecialchars($meeting_link) . "' style='display:inline-block; background:#28a745; color:#ffffff; text-decoration:none; padding:16px 40px; border-radius:8px; font-size:18px; font-weight:700; letter-spacing:0.5px;'>
                &#9654; Join Live Session Now
            </a>
        </div>
        
        <div class='info-box'>
            <h3>📹 Session Details</h3>
            <div class='info-row'>
                <span class='info-label'>Session Title</span>
                <span class='info-value'>" . htmlspecialchars($session_title) . "</span>
            </div>
            <div class='info-row'>
                <span class='info-label'>Instructor</span>
                <span class='info-value'>" . htmlspecialchars($lecturer_name) . "</span>
            </div>
            <div class='info-row'>
                <span class='info-label'>Date</span>
                <span class='info-value' style='color: #2563eb; font-weight: 600;'>" . date('l, F j, Y', strtotime($session_date)) . "</span>
            </div>
            <div class='info-row'>
                <span class='info-label'>Time</span>
                <span class='info-value' style='color: #2563eb; font-weight: 600;'>" . date('g:i A', strtotime($session_time)) . "</span>
            </div>
            " . ($meeting_password ? "<div class='info-row'>
                <span class='info-label'>Password</span>
                <span class='info-value' style='font-family: monospace;'>" . htmlspecialchars($meeting_password) . "</span>
            </div>" : "") . "
        </div>
        
        <div class='btn-container'>
            <a href='" . htmlspecialchars($meeting_link) . "' class='btn-primary'>Join Live Session</a>
        </div>

        <div style='text-align:center; margin: 10px 0;'>
            <p style='color:#666; font-size:13px;'>Or copy and paste this link into your browser:</p>
            <p style='word-break:break-all; font-size:13px; color:#2563eb;'><a href='" . htmlspecialchars($meeting_link) . "'>" . htmlspecialchars($meeting_link) . "</a></p>
        </div>
        " . ($invites_page_link ? "
        <div style='text-align:center; margin: 15px 0;'>
            <a href='" . htmlspecialchars($invites_page_link) . "' style='display:inline-block; background:#6c757d; color:#ffffff; text-decoration:none; padding:10px 24px; border-radius:6px; font-size:13px;'>
                View All Live Sessions
            </a>
        </div>
        " : "") . "
        
        <div class='alert-box info'>
            <strong>💡 Tips for Joining:</strong>
            <ul style='margin-top: 10px; padding-left: 20px;'>
                <li>Join 5 minutes early to test your audio/video</li>
                <li>Use headphones for better audio quality</li>
                <li>Find a quiet place with good internet connection</li>
                <li>Keep your microphone muted when not speaking</li>
            </ul>
        </div>
    ";
    
    $body = getEmailTemplate("Live Session Invitation", $content, '', '#7c3aed');
    return sendEmail($student_email, $student_name, $subject, $body);
}

/**
 * Send live session reminder (30 minutes before)
 */
function sendLiveSessionReminderEmail($student_email, $student_name, $course_name, $session_title, $meeting_link) {
    $subject = "⏰ Session Starting Soon: " . htmlspecialchars($session_title);
    
    $content = "
        <p class='greeting'>Dear <strong>" . htmlspecialchars($student_name) . "</strong>,</p>
        
        <div class='alert-box warning'>
            <strong>⏰ Reminder:</strong> Your live session is starting in <strong>30 minutes</strong>!
        </div>
        
        <div class='info-box'>
            <h3>📹 Session Information</h3>
            <div class='info-row'>
                <span class='info-label'>Course</span>
                <span class='info-value'>" . htmlspecialchars($course_name) . "</span>
            </div>
            <div class='info-row'>
                <span class='info-label'>Session</span>
                <span class='info-value'>" . htmlspecialchars($session_title) . "</span>
            </div>
            <div class='info-row'>
                <span class='info-label'>Starts At</span>
                <span class='info-value' style='color: #dc2626; font-weight: 600;'>" . date('g:i A') . " (30 mins from now)</span>
            </div>
        </div>
        
        <div class='btn-container'>
            <a href='" . htmlspecialchars($meeting_link) . "' class='btn-primary'>Join Now</a>
        </div>
    ";
    
    $body = getEmailTemplate("Session Reminder", $content, '', '#f59e0b');
    return sendEmail($student_email, $student_name, $subject, $body);
}

// ============================================================================
// VERIFICATION & SECURITY NOTIFICATIONS  
// ============================================================================

/**
 * Verify email address format
 */
function verifyEmailFormat($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL);
}

/**
 * Send email verification code
 */
function sendVerificationCode($email, $name, $code) {
    $uni = getUniversitySettings();
    $subject = "Verification Code - " . ($uni['university_name'] ?? 'VLE System');
    
    $content = "
        <p class='greeting'>Dear <strong>" . htmlspecialchars($name) . "</strong>,</p>
        
        <p class='content-text'>
            Please use the verification code below to verify your email address.
        </p>
        
        <div style='text-align: center; margin: 30px 0;'>
            <div style='background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%); 
                        border: 2px solid #2563eb; 
                        border-radius: 12px; 
                        padding: 30px; 
                        display: inline-block;'>
                <p style='color: #64748b; font-size: 14px; margin-bottom: 10px;'>Your Verification Code</p>
                <div style='font-size: 36px; 
                            font-weight: 700; 
                            letter-spacing: 8px; 
                            color: #2563eb; 
                            font-family: monospace;'>" . htmlspecialchars($code) . "</div>
            </div>
        </div>
        
        <div class='alert-box warning'>
            <strong>⏱️ Note:</strong> This verification code will expire in <strong>30 minutes</strong>.
        </div>
        
        <p class='content-text'>
            If you did not request this verification code, please ignore this email or contact support if you have concerns.
        </p>
    ";
    
    $body = getEmailTemplate("Email Verification", $content);
    return sendEmail($email, $name, $subject, $body);
}

/**
 * Send login alert for suspicious activity
 */
function sendLoginAlertEmail($email, $name, $ip_address, $device_info, $location = '') {
    $uni = getUniversitySettings();
    $subject = "🔒 Security Alert - New Login Detected";
    
    $content = "
        <p class='greeting'>Dear <strong>" . htmlspecialchars($name) . "</strong>,</p>
        
        <div class='alert-box warning'>
            <strong>🔒 Security Alert:</strong> A new login was detected on your account.
        </div>
        
        <div class='info-box'>
            <h3>📋 Login Details</h3>
            <div class='info-row'>
                <span class='info-label'>Date & Time</span>
                <span class='info-value'>" . date('F j, Y \a\t g:i A') . "</span>
            </div>
            <div class='info-row'>
                <span class='info-label'>IP Address</span>
                <span class='info-value' style='font-family: monospace;'>" . htmlspecialchars($ip_address) . "</span>
            </div>
            <div class='info-row'>
                <span class='info-label'>Device</span>
                <span class='info-value'>" . htmlspecialchars($device_info) . "</span>
            </div>
            " . ($location ? "<div class='info-row'>
                <span class='info-label'>Location</span>
                <span class='info-value'>" . htmlspecialchars($location) . "</span>
            </div>" : "") . "
        </div>
        
        <p class='content-text'>
            If this was you, no action is needed. If you don't recognize this activity, please:
        </p>
        
        <ol class='steps-list'>
            <li>Change your password immediately</li>
            <li>Review your account settings</li>
            <li>Contact support if needed</li>
        </ol>
        
        <div class='btn-container'>
            <a href='" . SYSTEM_URL . "/change_password.php' class='btn-primary'>Change Password</a>
        </div>
    ";
    
    $body = getEmailTemplate("Security Alert", $content, '', '#dc2626');
    return sendEmail($email, $name, $subject, $body);
}

/**
 * Send account locked notification (after failed login attempts)
 */
function sendAccountLockedEmail($email, $name, $failed_attempts, $lock_duration = '30 minutes') {
    $uni = getUniversitySettings();
    $subject = "🔒 Account Temporarily Locked - " . ($uni['university_name'] ?? 'VLE System');
    
    $content = "
        <p class='greeting'>Dear <strong>" . htmlspecialchars($name) . "</strong>,</p>
        
        <div class='alert-box'>
            <strong>🔒 Account Locked</strong><br>
            Your account has been temporarily locked due to multiple failed login attempts.
        </div>
        
        <div class='info-box'>
            <h3>📋 Security Details</h3>
            <div class='info-row'>
                <span class='info-label'>Failed Attempts</span>
                <span class='info-value' style='color: #dc2626;'>" . (int)$failed_attempts . " attempts</span>
            </div>
            <div class='info-row'>
                <span class='info-label'>Lock Duration</span>
                <span class='info-value'>" . htmlspecialchars($lock_duration) . "</span>
            </div>
            <div class='info-row'>
                <span class='info-label'>Locked At</span>
                <span class='info-value'>" . date('F j, Y \a\t g:i A') . "</span>
            </div>
        </div>
        
        <p class='content-text'>
            This security measure protects your account from unauthorized access. You can try logging in again after the lock period expires.
        </p>
        
        <div class='alert-box warning'>
            <strong>⚠️ Not You?</strong> If you did not attempt to log in, someone may be trying to access your account. Please contact the administrator immediately and consider changing your password.
        </div>
        
        <div class='btn-container'>
            <a href='" . SYSTEM_URL . "/forgot_password.php' class='btn-primary'>Reset Password</a>
        </div>
    ";
    
    $body = getEmailTemplate("Account Locked", $content, '', '#dc2626');
    return sendEmail($email, $name, $subject, $body);
}

/**
 * Send account reactivated/unlocked notification
 */
function sendAccountUnlockedEmail($email, $name, $unlocked_by_admin = false) {
    $uni = getUniversitySettings();
    $subject = "✅ Account Unlocked - " . ($uni['university_name'] ?? 'VLE System');
    
    $content = "
        <p class='greeting'>Dear <strong>" . htmlspecialchars($name) . "</strong>,</p>
        
        <div class='alert-box success'>
            <strong>✅ Account Unlocked!</strong> Your account has been " . ($unlocked_by_admin ? "unlocked by an administrator" : "automatically unlocked") . ".
        </div>
        
        <p class='content-text'>
            You can now log in to your account. " . ($unlocked_by_admin ? "" : "The temporary lock period has expired.") . "
        </p>
        
        <div class='alert-box info'>
            <strong>💡 Security Tips:</strong>
            <ul style='margin-top: 10px; padding-left: 20px;'>
                <li>Use a strong, unique password</li>
                <li>Enable two-factor authentication if available</li>
                <li>Never share your login credentials</li>
                <li>Log out when using public computers</li>
            </ul>
        </div>
        
        <div class='btn-container'>
            <a href='" . SYSTEM_URL . "/login.php' class='btn-primary'>Login Now</a>
        </div>
    ";
    
    $body = getEmailTemplate("Account Unlocked", $content, '', '#059669');
    return sendEmail($email, $name, $subject, $body);
}

// ============================================================================
// COURSE MANAGEMENT NOTIFICATIONS
// ============================================================================

/**
 * Send course enrollment/addition confirmation
 */
function sendCourseAddedEmail($student_email, $student_name, $course_name, $course_code, $lecturer_name, $semester, $academic_year) {
    $uni = getUniversitySettings();
    $subject = "Course Enrolled: " . htmlspecialchars($course_code) . " - " . htmlspecialchars($course_name);
    
    $content = "
        <p class='greeting'>Dear <strong>" . htmlspecialchars($student_name) . "</strong>,</p>
        
        <div class='alert-box success'>
            <strong>🎉 Enrollment Confirmed!</strong> You have been successfully enrolled in a new course.
        </div>
        
        <div class='info-box'>
            <h3>📚 Course Details</h3>
            <div class='info-row'>
                <span class='info-label'>Course Code</span>
                <span class='info-value'>" . htmlspecialchars($course_code) . "</span>
            </div>
            <div class='info-row'>
                <span class='info-label'>Course Name</span>
                <span class='info-value'>" . htmlspecialchars($course_name) . "</span>
            </div>
            <div class='info-row'>
                <span class='info-label'>Instructor</span>
                <span class='info-value'>" . htmlspecialchars($lecturer_name) . "</span>
            </div>
            <div class='info-row'>
                <span class='info-label'>Semester</span>
                <span class='info-value'>" . htmlspecialchars($semester) . "</span>
            </div>
            <div class='info-row'>
                <span class='info-label'>Academic Year</span>
                <span class='info-value'>" . htmlspecialchars($academic_year) . "</span>
            </div>
            <div class='info-row'>
                <span class='info-label'>Enrolled On</span>
                <span class='info-value'>" . date('F j, Y') . "</span>
            </div>
        </div>
        
        <p class='content-text'><strong>What's Next?</strong></p>
        <ol class='steps-list'>
            <li>Review the course syllabus and schedule</li>
            <li>Access course materials and resources</li>
            <li>Check assignment deadlines</li>
            <li>Join any scheduled live sessions</li>
        </ol>
        
        <div class='btn-container'>
            <a href='" . SYSTEM_URL . "/student/dashboard.php' class='btn-primary'>Go to My Courses</a>
        </div>
    ";
    
    $body = getEmailTemplate("Course Enrollment", $content, '', '#059669');
    return sendEmail($student_email, $student_name, $subject, $body);
}

/**
 * Send course drop/removal notification
 */
function sendCourseDroppedEmail($student_email, $student_name, $course_name, $course_code, $drop_reason = '', $dropped_by_admin = false) {
    $uni = getUniversitySettings();
    $subject = "Course Dropped: " . htmlspecialchars($course_code);
    
    $content = "
        <p class='greeting'>Dear <strong>" . htmlspecialchars($student_name) . "</strong>,</p>
        
        <p class='content-text'>
            This is to confirm that you have been " . ($dropped_by_admin ? "removed from" : "unenrolled from") . " the following course.
        </p>
        
        <div class='info-box'>
            <h3>📋 Course Information</h3>
            <div class='info-row'>
                <span class='info-label'>Course Code</span>
                <span class='info-value'>" . htmlspecialchars($course_code) . "</span>
            </div>
            <div class='info-row'>
                <span class='info-label'>Course Name</span>
                <span class='info-value'>" . htmlspecialchars($course_name) . "</span>
            </div>
            <div class='info-row'>
                <span class='info-label'>Status</span>
                <span class='info-value' style='color: #dc2626;'>Dropped</span>
            </div>
            <div class='info-row'>
                <span class='info-label'>Date</span>
                <span class='info-value'>" . date('F j, Y') . "</span>
            </div>
        </div>
        
        " . ($drop_reason ? "<div class='alert-box info'>
            <strong>📝 Reason:</strong> " . htmlspecialchars($drop_reason) . "
        </div>" : "") . "
        
        <p class='content-text'>
            You will no longer have access to course materials, assignments, or live sessions for this course. If you believe this was done in error, please contact the administrator.
        </p>
        
        <div class='btn-container'>
            <a href='" . SYSTEM_URL . "/student/dashboard.php' class='btn-primary'>View My Courses</a>
        </div>
    ";
    
    $body = getEmailTemplate("Course Dropped", $content);
    return sendEmail($student_email, $student_name, $subject, $body);
}

// ============================================================================
// ASSIGNMENT REMINDER NOTIFICATIONS
// ============================================================================

/**
 * Send assignment due soon reminder (24/48 hours before deadline)
 */
function sendAssignmentDueReminderEmail($student_email, $student_name, $assignment_title, $course_name, $due_date, $hours_remaining, $course_id) {
    $uni = getUniversitySettings();
    $subject = "⏰ Assignment Due Soon: " . htmlspecialchars($assignment_title);
    
    $urgency_color = $hours_remaining <= 24 ? '#dc2626' : '#f59e0b';
    $urgency_text = $hours_remaining <= 24 ? 'Due in less than 24 hours!' : 'Due in ' . $hours_remaining . ' hours';
    
    $content = "
        <p class='greeting'>Dear <strong>" . htmlspecialchars($student_name) . "</strong>,</p>
        
        <div class='alert-box " . ($hours_remaining <= 24 ? '' : 'warning') . "'>
            <strong>⏰ Deadline Approaching!</strong> $urgency_text
        </div>
        
        <div class='info-box'>
            <h3>📝 Assignment Details</h3>
            <div class='info-row'>
                <span class='info-label'>Course</span>
                <span class='info-value'>" . htmlspecialchars($course_name) . "</span>
            </div>
            <div class='info-row'>
                <span class='info-label'>Assignment</span>
                <span class='info-value'>" . htmlspecialchars($assignment_title) . "</span>
            </div>
            <div class='info-row'>
                <span class='info-label'>Due Date</span>
                <span class='info-value' style='color: $urgency_color; font-weight: 700;'>" . date('l, F j, Y \a\t g:i A', strtotime($due_date)) . "</span>
            </div>
            <div class='info-row'>
                <span class='info-label'>Time Remaining</span>
                <span class='info-value' style='color: $urgency_color; font-weight: 700;'>~" . $hours_remaining . " hours</span>
            </div>
        </div>
        
        <p class='content-text'>
            Don't forget to submit your assignment before the deadline. Late submissions may result in grade penalties.
        </p>
        
        <div class='btn-container'>
            <a href='" . SYSTEM_URL . "/student/dashboard.php?course_id=" . (int)$course_id . "' class='btn-primary'>Submit Assignment</a>
        </div>
    ";
    
    $body = getEmailTemplate("Assignment Reminder", $content, '', $urgency_color);
    return sendEmail($student_email, $student_name, $subject, $body);
}

/**
 * Send late submission warning (deadline passed, submission still open)
 */
function sendLateSubmissionWarningEmail($student_email, $student_name, $assignment_title, $course_name, $due_date, $late_policy, $course_id) {
    $uni = getUniversitySettings();
    $subject = "⚠️ Late Submission: " . htmlspecialchars($assignment_title);
    
    $content = "
        <p class='greeting'>Dear <strong>" . htmlspecialchars($student_name) . "</strong>,</p>
        
        <div class='alert-box'>
            <strong>⚠️ Deadline Passed!</strong> The deadline for this assignment has passed.
        </div>
        
        <div class='info-box'>
            <h3>📝 Assignment Details</h3>
            <div class='info-row'>
                <span class='info-label'>Course</span>
                <span class='info-value'>" . htmlspecialchars($course_name) . "</span>
            </div>
            <div class='info-row'>
                <span class='info-label'>Assignment</span>
                <span class='info-value'>" . htmlspecialchars($assignment_title) . "</span>
            </div>
            <div class='info-row'>
                <span class='info-label'>Original Due Date</span>
                <span class='info-value' style='color: #dc2626;'>" . date('F j, Y \a\t g:i A', strtotime($due_date)) . "</span>
            </div>
            <div class='info-row'>
                <span class='info-label'>Status</span>
                <span class='info-value' style='color: #dc2626; font-weight: 700;'>OVERDUE</span>
            </div>
        </div>
        
        " . ($late_policy ? "<div class='alert-box warning'>
            <strong>📋 Late Submission Policy:</strong><br>" . nl2br(htmlspecialchars($late_policy)) . "
        </div>" : "<div class='alert-box warning'>
            <strong>⚠️ Note:</strong> Late submissions may be subject to grade penalties. Please submit as soon as possible.
        </div>") . "
        
        <div class='btn-container'>
            <a href='" . SYSTEM_URL . "/student/dashboard.php?course_id=" . (int)$course_id . "' class='btn-primary'>Submit Now</a>
        </div>
    ";
    
    $body = getEmailTemplate("Late Submission Warning", $content, '', '#dc2626');
    return sendEmail($student_email, $student_name, $subject, $body);
}

/**
 * Send grade posted notification (when grades are published)
 */
function sendGradePostedEmail($student_email, $student_name, $course_name, $assessment_type, $assessment_name, $course_id) {
    $uni = getUniversitySettings();
    $subject = "📊 Grade Posted: " . htmlspecialchars($assessment_name);
    
    $content = "
        <p class='greeting'>Dear <strong>" . htmlspecialchars($student_name) . "</strong>,</p>
        
        <div class='alert-box success'>
            <strong>📊 New Grade Available!</strong> A grade has been posted for you.
        </div>
        
        <div class='info-box'>
            <h3>📋 Grade Information</h3>
            <div class='info-row'>
                <span class='info-label'>Course</span>
                <span class='info-value'>" . htmlspecialchars($course_name) . "</span>
            </div>
            <div class='info-row'>
                <span class='info-label'>Assessment Type</span>
                <span class='info-value'>" . htmlspecialchars($assessment_type) . "</span>
            </div>
            <div class='info-row'>
                <span class='info-label'>Assessment</span>
                <span class='info-value'>" . htmlspecialchars($assessment_name) . "</span>
            </div>
            <div class='info-row'>
                <span class='info-label'>Posted On</span>
                <span class='info-value'>" . date('F j, Y') . "</span>
            </div>
        </div>
        
        <p class='content-text'>
            Log in to view your grade and any feedback from your instructor.
        </p>
        
        <div class='btn-container'>
            <a href='" . SYSTEM_URL . "/student/dashboard.php?course_id=" . (int)$course_id . "&view=grades' class='btn-primary'>View Grade</a>
        </div>
    ";
    
    $body = getEmailTemplate("Grade Posted", $content, '', '#059669');
    return sendEmail($student_email, $student_name, $subject, $body);
}

/**
 * Send grade updated/corrected notification
 */
function sendGradeUpdatedEmail($student_email, $student_name, $course_name, $assessment_name, $old_score, $new_score, $course_id, $reason = '') {
    $uni = getUniversitySettings();
    $subject = "📝 Grade Updated: " . htmlspecialchars($assessment_name);
    
    $score_change = $new_score - $old_score;
    $change_color = $score_change >= 0 ? '#059669' : '#dc2626';
    $change_text = $score_change >= 0 ? '+' . number_format($score_change, 1) : number_format($score_change, 1);
    
    $content = "
        <p class='greeting'>Dear <strong>" . htmlspecialchars($student_name) . "</strong>,</p>
        
        <div class='alert-box info'>
            <strong>📝 Grade Update</strong> Your grade for this assessment has been updated.
        </div>
        
        <div class='info-box'>
            <h3>📋 Grade Details</h3>
            <div class='info-row'>
                <span class='info-label'>Course</span>
                <span class='info-value'>" . htmlspecialchars($course_name) . "</span>
            </div>
            <div class='info-row'>
                <span class='info-label'>Assessment</span>
                <span class='info-value'>" . htmlspecialchars($assessment_name) . "</span>
            </div>
            <div class='info-row'>
                <span class='info-label'>Previous Grade</span>
                <span class='info-value' style='text-decoration: line-through; color: #94a3b8;'>" . number_format($old_score, 1) . "%</span>
            </div>
            <div class='info-row'>
                <span class='info-label'>New Grade</span>
                <span class='info-value' style='color: #2563eb; font-weight: 700;'>" . number_format($new_score, 1) . "%</span>
            </div>
            <div class='info-row'>
                <span class='info-label'>Change</span>
                <span class='info-value' style='color: $change_color; font-weight: 600;'>$change_text%</span>
            </div>
            <div class='info-row'>
                <span class='info-label'>Updated On</span>
                <span class='info-value'>" . date('F j, Y \a\t g:i A') . "</span>
            </div>
        </div>
        
        " . ($reason ? "<div class='alert-box info'>
            <strong>📋 Reason for Update:</strong> " . htmlspecialchars($reason) . "
        </div>" : "") . "
        
        <div class='btn-container'>
            <a href='" . SYSTEM_URL . "/student/dashboard.php?course_id=" . (int)$course_id . "&view=grades' class='btn-primary'>View Updated Grade</a>
        </div>
    ";
    
    $body = getEmailTemplate("Grade Updated", $content);
    return sendEmail($student_email, $student_name, $subject, $body);
}

// ============================================================================
// ADDITIONAL LIVE SESSION NOTIFICATIONS
// ============================================================================

/**
 * Send live session cancelled notification
 */
function sendLiveSessionCancelledEmail($student_email, $student_name, $course_name, $session_title, $original_date, $original_time, $cancel_reason = '', $rescheduled = false, $new_date = '', $new_time = '') {
    $uni = getUniversitySettings();
    $subject = "❌ Live Session Cancelled: " . htmlspecialchars($session_title);
    
    $reschedule_info = '';
    if ($rescheduled && $new_date && $new_time) {
        $reschedule_info = "
        <div class='alert-box success'>
            <strong>📅 Rescheduled Session</strong><br>
            The session has been rescheduled to:<br>
            <strong>" . date('l, F j, Y', strtotime($new_date)) . " at " . date('g:i A', strtotime($new_time)) . "</strong>
        </div>";
    }
    
    $content = "
        <p class='greeting'>Dear <strong>" . htmlspecialchars($student_name) . "</strong>,</p>
        
        <div class='alert-box'>
            <strong>❌ Session Cancelled</strong> The following live session has been cancelled.
        </div>
        
        <div class='info-box'>
            <h3>📹 Cancelled Session Details</h3>
            <div class='info-row'>
                <span class='info-label'>Course</span>
                <span class='info-value'>" . htmlspecialchars($course_name) . "</span>
            </div>
            <div class='info-row'>
                <span class='info-label'>Session</span>
                <span class='info-value'>" . htmlspecialchars($session_title) . "</span>
            </div>
            <div class='info-row'>
                <span class='info-label'>Original Date</span>
                <span class='info-value' style='text-decoration: line-through; color: #94a3b8;'>" . date('l, F j, Y', strtotime($original_date)) . "</span>
            </div>
            <div class='info-row'>
                <span class='info-label'>Original Time</span>
                <span class='info-value' style='text-decoration: line-through; color: #94a3b8;'>" . date('g:i A', strtotime($original_time)) . "</span>
            </div>
        </div>
        
        " . ($cancel_reason ? "<div class='alert-box info'>
            <strong>📝 Reason:</strong> " . htmlspecialchars($cancel_reason) . "
        </div>" : "") . "
        
        $reschedule_info
        
        <p class='content-text'>
            We apologize for any inconvenience. Please check your course page for updates on rescheduled sessions.
        </p>
        
        <div class='btn-container'>
            <a href='" . SYSTEM_URL . "/student/dashboard.php' class='btn-primary'>View Course Updates</a>
        </div>
    ";
    
    $body = getEmailTemplate("Session Cancelled", $content, '', '#dc2626');
    return sendEmail($student_email, $student_name, $subject, $body);
}

/**
 * Send live session recording available notification
 */
function sendRecordingAvailableEmail($student_email, $student_name, $course_name, $session_title, $session_date, $recording_url, $expiry_date = '') {
    $uni = getUniversitySettings();
    $subject = "🎬 Recording Available: " . htmlspecialchars($session_title);
    
    $content = "
        <p class='greeting'>Dear <strong>" . htmlspecialchars($student_name) . "</strong>,</p>
        
        <div class='alert-box success'>
            <strong>🎬 Recording Now Available!</strong> The recording of your live session is ready to view.
        </div>
        
        <div class='info-box'>
            <h3>📹 Session Recording</h3>
            <div class='info-row'>
                <span class='info-label'>Course</span>
                <span class='info-value'>" . htmlspecialchars($course_name) . "</span>
            </div>
            <div class='info-row'>
                <span class='info-label'>Session</span>
                <span class='info-value'>" . htmlspecialchars($session_title) . "</span>
            </div>
            <div class='info-row'>
                <span class='info-label'>Session Date</span>
                <span class='info-value'>" . date('F j, Y', strtotime($session_date)) . "</span>
            </div>
            " . ($expiry_date ? "<div class='info-row'>
                <span class='info-label'>Available Until</span>
                <span class='info-value' style='color: #f59e0b;'>" . date('F j, Y', strtotime($expiry_date)) . "</span>
            </div>" : "") . "
        </div>
        
        <div class='btn-container'>
            <a href='" . htmlspecialchars($recording_url) . "' class='btn-primary'>Watch Recording</a>
        </div>
        
        " . ($expiry_date ? "<div class='alert-box warning'>
            <strong>⏰ Note:</strong> This recording will be available until " . date('F j, Y', strtotime($expiry_date)) . ". Please watch it before it expires.
        </div>" : "") . "
    ";
    
    $body = getEmailTemplate("Recording Available", $content, '', '#7c3aed');
    return sendEmail($student_email, $student_name, $subject, $body);
}

// ============================================================================
// ADDITIONAL PAYMENT NOTIFICATIONS
// ============================================================================

/**
 * Send payment due notification (fee deadline approaching)
 */
function sendPaymentDueEmail($student_email, $student_name, $amount_due, $due_date, $fee_description = '', $days_until_due = 0) {
    $uni = getUniversitySettings();
    $subject = "💰 Payment Due: " . ($fee_description ?: "Tuition Fees");
    
    $urgency_color = $days_until_due <= 3 ? '#dc2626' : ($days_until_due <= 7 ? '#f59e0b' : '#2563eb');
    
    $content = "
        <p class='greeting'>Dear <strong>" . htmlspecialchars($student_name) . "</strong>,</p>
        
        <div class='alert-box " . ($days_until_due <= 3 ? '' : 'warning') . "'>
            <strong>" . ($days_until_due <= 3 ? '🚨 Urgent:' : '⏰') . " Payment Due " . ($days_until_due <= 0 ? 'Today!' : "in $days_until_due days") . "</strong>
        </div>
        
        <div class='info-box'>
            <h3>💰 Payment Details</h3>
            <div class='info-row'>
                <span class='info-label'>Description</span>
                <span class='info-value'>" . htmlspecialchars($fee_description ?: 'Tuition Fees') . "</span>
            </div>
            <div class='info-row'>
                <span class='info-label'>Amount Due</span>
                <span class='info-value' style='color: $urgency_color; font-weight: 700; font-size: 18px;'>K" . number_format($amount_due, 2) . "</span>
            </div>
            <div class='info-row'>
                <span class='info-label'>Due Date</span>
                <span class='info-value' style='color: $urgency_color; font-weight: 600;'>" . date('l, F j, Y', strtotime($due_date)) . "</span>
            </div>
        </div>
        
        <div class='alert-box info'>
            <strong>💡 Payment Options:</strong>
            <ul style='margin-top: 10px; padding-left: 20px;'>
                <li>Online payment through the student portal</li>
                <li>Bank transfer to the university account</li>
                <li>Visit the Finance Office for cash/card payment</li>
            </ul>
        </div>
        
        <div class='btn-container'>
            <a href='" . SYSTEM_URL . "/student/dashboard.php' class='btn-primary'>Make Payment</a>
        </div>
        
        <p class='content-text' style='font-size: 13px; color: #64748b;'>
            Note: Failure to pay by the due date may result in late fees or restricted access to certain features. If you need a payment plan, please contact the Finance Office.
        </p>
    ";
    
    $body = getEmailTemplate("Payment Due", $content, '', $urgency_color);
    return sendEmail($student_email, $student_name, $subject, $body);
}

/**
 * Send payment failed notification
 */
function sendPaymentFailedEmail($student_email, $student_name, $amount, $payment_method, $error_reason, $transaction_ref = '') {
    $uni = getUniversitySettings();
    $subject = "❌ Payment Failed - " . ($uni['university_name'] ?? 'VLE System');
    
    $content = "
        <p class='greeting'>Dear <strong>" . htmlspecialchars($student_name) . "</strong>,</p>
        
        <div class='alert-box'>
            <strong>❌ Payment Unsuccessful</strong> Your payment could not be processed.
        </div>
        
        <div class='info-box'>
            <h3>💳 Transaction Details</h3>
            <div class='info-row'>
                <span class='info-label'>Amount</span>
                <span class='info-value'>K" . number_format($amount, 2) . "</span>
            </div>
            <div class='info-row'>
                <span class='info-label'>Payment Method</span>
                <span class='info-value'>" . htmlspecialchars($payment_method) . "</span>
            </div>
            " . ($transaction_ref ? "<div class='info-row'>
                <span class='info-label'>Reference</span>
                <span class='info-value' style='font-family: monospace;'>" . htmlspecialchars($transaction_ref) . "</span>
            </div>" : "") . "
            <div class='info-row'>
                <span class='info-label'>Date</span>
                <span class='info-value'>" . date('F j, Y \a\t g:i A') . "</span>
            </div>
            <div class='info-row'>
                <span class='info-label'>Status</span>
                <span class='info-value' style='color: #dc2626; font-weight: 600;'>FAILED</span>
            </div>
        </div>
        
        <div class='alert-box warning'>
            <strong>📋 Reason:</strong> " . htmlspecialchars($error_reason) . "
        </div>
        
        <p class='content-text'><strong>What to do next:</strong></p>
        <ol class='steps-list'>
            <li>Verify your payment details are correct</li>
            <li>Ensure sufficient funds are available</li>
            <li>Try a different payment method if problem persists</li>
            <li>Contact your bank if the issue continues</li>
        </ol>
        
        <div class='btn-container'>
            <a href='" . SYSTEM_URL . "/student/dashboard.php' class='btn-primary'>Try Again</a>
        </div>
        
        <p class='content-text' style='font-size: 13px;'>
            If you continue to experience issues, please contact the Finance Office for assistance.
        </p>
    ";
    
    $body = getEmailTemplate("Payment Failed", $content, '', '#dc2626');
    return sendEmail($student_email, $student_name, $subject, $body);
}

/**
 * Send fee structure updated notification
 */
function sendFeeStructureUpdatedEmail($student_email, $student_name, $program_name, $changes_summary, $effective_date, $new_total = 0) {
    $uni = getUniversitySettings();
    $subject = "📋 Fee Structure Update - " . ($uni['university_name'] ?? 'VLE System');
    
    $content = "
        <p class='greeting'>Dear <strong>" . htmlspecialchars($student_name) . "</strong>,</p>
        
        <div class='alert-box info'>
            <strong>📋 Fee Structure Notice</strong> There have been updates to the fee structure.
        </div>
        
        <div class='info-box'>
            <h3>📚 Program Information</h3>
            <div class='info-row'>
                <span class='info-label'>Program</span>
                <span class='info-value'>" . htmlspecialchars($program_name) . "</span>
            </div>
            <div class='info-row'>
                <span class='info-label'>Effective Date</span>
                <span class='info-value'>" . date('F j, Y', strtotime($effective_date)) . "</span>
            </div>
            " . ($new_total > 0 ? "<div class='info-row'>
                <span class='info-label'>New Total</span>
                <span class='info-value' style='font-weight: 700;'>K" . number_format($new_total, 2) . "</span>
            </div>" : "") . "
        </div>
        
        <div class='info-box'>
            <h3>📝 Summary of Changes</h3>
            <p style='color: #374151; line-height: 1.8;'>" . nl2br(htmlspecialchars($changes_summary)) . "</p>
        </div>
        
        <p class='content-text'>
            Please log in to your student portal to view the complete updated fee structure. If you have any questions, please contact the Finance Office.
        </p>
        
        <div class='btn-container'>
            <a href='" . SYSTEM_URL . "/student/dashboard.php' class='btn-primary'>View Fee Details</a>
        </div>
    ";
    
    $body = getEmailTemplate("Fee Structure Update", $content, '', '#0891b2');
    return sendEmail($student_email, $student_name, $subject, $body);
}

// ============================================================================
// DISCUSSION & FORUM NOTIFICATIONS
// ============================================================================

/**
 * Send discussion reply notification
 */
function sendDiscussionReplyEmail($recipient_email, $recipient_name, $replier_name, $course_name, $discussion_title, $reply_preview, $discussion_id) {
    $uni = getUniversitySettings();
    $subject = "💬 New Reply: " . htmlspecialchars($discussion_title);
    
    $preview = substr(strip_tags($reply_preview), 0, 250);
    if (strlen($reply_preview) > 250) $preview .= '...';
    
    $content = "
        <p class='greeting'>Dear <strong>" . htmlspecialchars($recipient_name) . "</strong>,</p>
        
        <p class='content-text'>
            <strong>" . htmlspecialchars($replier_name) . "</strong> replied to a discussion you're following.
        </p>
        
        <div class='info-box'>
            <h3>💬 Discussion Details</h3>
            <div class='info-row'>
                <span class='info-label'>Course</span>
                <span class='info-value'>" . htmlspecialchars($course_name) . "</span>
            </div>
            <div class='info-row'>
                <span class='info-label'>Topic</span>
                <span class='info-value'>" . htmlspecialchars($discussion_title) . "</span>
            </div>
            <div class='info-row'>
                <span class='info-label'>Reply By</span>
                <span class='info-value'>" . htmlspecialchars($replier_name) . "</span>
            </div>
            <div class='info-row'>
                <span class='info-label'>Date</span>
                <span class='info-value'>" . date('F j, Y \a\t g:i A') . "</span>
            </div>
        </div>
        
        <div style='background: #f8fafc; border-radius: 8px; padding: 20px; margin: 20px 0; border-left: 4px solid #2563eb;'>
            <p style='color: #475569; font-style: italic; margin: 0;'>\"" . htmlspecialchars($preview) . "\"</p>
        </div>
        
        <div class='btn-container'>
            <a href='" . SYSTEM_URL . "/student/discussion.php?id=" . (int)$discussion_id . "' class='btn-primary'>View Full Discussion</a>
        </div>
    ";
    
    $body = getEmailTemplate("New Discussion Reply", $content, '', '#0891b2');
    return sendEmail($recipient_email, $recipient_name, $subject, $body);
}

/**
 * Send mention notification (tagged in discussion or comment)
 */
function sendMentionNotificationEmail($recipient_email, $recipient_name, $mentioner_name, $context_type, $context_title, $mention_preview, $link_url) {
    $uni = getUniversitySettings();
    $subject = "🔔 You were mentioned by " . htmlspecialchars($mentioner_name);
    
    $preview = substr(strip_tags($mention_preview), 0, 200);
    if (strlen($mention_preview) > 200) $preview .= '...';
    
    $content = "
        <p class='greeting'>Dear <strong>" . htmlspecialchars($recipient_name) . "</strong>,</p>
        
        <div class='alert-box info'>
            <strong>🔔 You've Been Mentioned!</strong> " . htmlspecialchars($mentioner_name) . " mentioned you in a " . htmlspecialchars($context_type) . ".
        </div>
        
        <div class='info-box'>
            <h3>📋 Mention Details</h3>
            <div class='info-row'>
                <span class='info-label'>Mentioned By</span>
                <span class='info-value'>" . htmlspecialchars($mentioner_name) . "</span>
            </div>
            <div class='info-row'>
                <span class='info-label'>Context</span>
                <span class='info-value'>" . htmlspecialchars($context_type) . ": " . htmlspecialchars($context_title) . "</span>
            </div>
            <div class='info-row'>
                <span class='info-label'>Date</span>
                <span class='info-value'>" . date('F j, Y \a\t g:i A') . "</span>
            </div>
        </div>
        
        <div style='background: #f8fafc; border-radius: 8px; padding: 20px; margin: 20px 0; border-left: 4px solid #f59e0b;'>
            <p style='color: #475569; margin: 0;'>\"..." . htmlspecialchars($preview) . "...\"</p>
        </div>
        
        <div class='btn-container'>
            <a href='" . htmlspecialchars($link_url) . "' class='btn-primary'>View & Respond</a>
        </div>
    ";
    
    $body = getEmailTemplate("You Were Mentioned", $content, '', '#f59e0b');
    return sendEmail($recipient_email, $recipient_name, $subject, $body);
}

// ============================================================================
// ADMINISTRATIVE NOTIFICATIONS
// ============================================================================

/**
 * Send system maintenance notification
 */
function sendMaintenanceNotificationEmail($email, $name, $maintenance_date, $start_time, $end_time, $affected_services = '', $maintenance_reason = '') {
    $uni = getUniversitySettings();
    $subject = "🔧 Scheduled Maintenance - " . ($uni['university_name'] ?? 'VLE System');
    
    $content = "
        <p class='greeting'>Dear <strong>" . htmlspecialchars($name) . "</strong>,</p>
        
        <div class='alert-box warning'>
            <strong>🔧 Scheduled System Maintenance</strong><br>
            The VLE system will undergo scheduled maintenance.
        </div>
        
        <div class='info-box'>
            <h3>📅 Maintenance Schedule</h3>
            <div class='info-row'>
                <span class='info-label'>Date</span>
                <span class='info-value' style='font-weight: 600;'>" . date('l, F j, Y', strtotime($maintenance_date)) . "</span>
            </div>
            <div class='info-row'>
                <span class='info-label'>Start Time</span>
                <span class='info-value'>" . date('g:i A', strtotime($start_time)) . "</span>
            </div>
            <div class='info-row'>
                <span class='info-label'>End Time</span>
                <span class='info-value'>" . date('g:i A', strtotime($end_time)) . " (estimated)</span>
            </div>
        </div>
        
        " . ($affected_services ? "<div class='info-box'>
            <h3>⚠️ Affected Services</h3>
            <p style='color: #374151;'>" . nl2br(htmlspecialchars($affected_services)) . "</p>
        </div>" : "") . "
        
        " . ($maintenance_reason ? "<div class='alert-box info'>
            <strong>📋 Purpose:</strong> " . htmlspecialchars($maintenance_reason) . "
        </div>" : "") . "
        
        <p class='content-text'>
            During this time, the system may be unavailable or have limited functionality. We recommend saving your work before the maintenance window begins.
        </p>
        
        <p class='content-text'>
            We apologize for any inconvenience and appreciate your patience as we work to improve our services.
        </p>
    ";
    
    $body = getEmailTemplate("System Maintenance", $content, '', '#f59e0b');
    return sendEmail($email, $name, $subject, $body);
}

/**
 * Send policy update notification
 */
function sendPolicyUpdateEmail($email, $name, $policy_type, $summary_of_changes, $effective_date, $policy_url = '') {
    $uni = getUniversitySettings();
    $subject = "📜 Policy Update: " . htmlspecialchars($policy_type);
    
    $content = "
        <p class='greeting'>Dear <strong>" . htmlspecialchars($name) . "</strong>,</p>
        
        <div class='alert-box info'>
            <strong>📜 Important Policy Update</strong><br>
            We have updated our " . htmlspecialchars($policy_type) . ".
        </div>
        
        <div class='info-box'>
            <h3>📋 Update Details</h3>
            <div class='info-row'>
                <span class='info-label'>Policy</span>
                <span class='info-value'>" . htmlspecialchars($policy_type) . "</span>
            </div>
            <div class='info-row'>
                <span class='info-label'>Effective Date</span>
                <span class='info-value' style='font-weight: 600;'>" . date('F j, Y', strtotime($effective_date)) . "</span>
            </div>
        </div>
        
        <div class='info-box'>
            <h3>📝 Summary of Changes</h3>
            <p style='color: #374151; line-height: 1.8;'>" . nl2br(htmlspecialchars($summary_of_changes)) . "</p>
        </div>
        
        <p class='content-text'>
            Please take a moment to review the updated policy. By continuing to use our services after the effective date, you acknowledge and agree to the updated terms.
        </p>
        
        " . ($policy_url ? "<div class='btn-container'>
            <a href='" . htmlspecialchars($policy_url) . "' class='btn-primary'>Read Full Policy</a>
        </div>" : "") . "
        
        <p class='content-text' style='font-size: 13px; color: #64748b;'>
            If you have any questions about these changes, please contact the administration.
        </p>
    ";
    
    $body = getEmailTemplate("Policy Update", $content, '', '#0891b2');
    return sendEmail($email, $name, $subject, $body);
}

/**
 * Send new document/material uploaded notification
 */
function sendDocumentUploadedEmail($student_email, $student_name, $lecturer_name, $course_name, $document_title, $document_type, $document_description = '', $course_id = 0) {
    $uni = getUniversitySettings();
    $subject = "📄 New Material: " . htmlspecialchars($document_title);
    
    $type_icons = [
        'lecture' => '📚',
        'slides' => '🎯',
        'notes' => '📝',
        'reading' => '📖',
        'video' => '🎬',
        'assignment' => '✍️',
        'reference' => '📎',
        'default' => '📄'
    ];
    $icon = $type_icons[strtolower($document_type)] ?? $type_icons['default'];
    
    $content = "
        <p class='greeting'>Dear <strong>" . htmlspecialchars($student_name) . "</strong>,</p>
        
        <div class='alert-box success'>
            <strong>$icon New Course Material Available!</strong>
        </div>
        
        <div class='info-box'>
            <h3>📄 Document Details</h3>
            <div class='info-row'>
                <span class='info-label'>Course</span>
                <span class='info-value'>" . htmlspecialchars($course_name) . "</span>
            </div>
            <div class='info-row'>
                <span class='info-label'>Title</span>
                <span class='info-value'>" . htmlspecialchars($document_title) . "</span>
            </div>
            <div class='info-row'>
                <span class='info-label'>Type</span>
                <span class='info-value'>" . htmlspecialchars(ucfirst($document_type)) . "</span>
            </div>
            <div class='info-row'>
                <span class='info-label'>Uploaded By</span>
                <span class='info-value'>" . htmlspecialchars($lecturer_name) . "</span>
            </div>
            <div class='info-row'>
                <span class='info-label'>Date</span>
                <span class='info-value'>" . date('F j, Y \a\t g:i A') . "</span>
            </div>
        </div>
        
        " . ($document_description ? "<div class='info-box'>
            <h3>📋 Description</h3>
            <p style='color: #374151; line-height: 1.8;'>" . nl2br(htmlspecialchars($document_description)) . "</p>
        </div>" : "") . "
        
        <div class='btn-container'>
            <a href='" . SYSTEM_URL . "/student/dashboard.php" . ($course_id ? "?course_id=" . (int)$course_id : "") . "' class='btn-primary'>Access Material</a>
        </div>
    ";
    
    $body = getEmailTemplate("New Course Material", $content, '', '#059669');
    return sendEmail($student_email, $student_name, $subject, $body);
}

/**
 * Send certificate ready notification
 */
function sendCertificateReadyEmail($student_email, $student_name, $certificate_type, $course_or_program_name, $completion_date, $certificate_id, $download_url = '') {
    $uni = getUniversitySettings();
    $subject = "🎓 Certificate Ready: " . htmlspecialchars($certificate_type);
    
    $content = "
        <p class='greeting'>Dear <strong>" . htmlspecialchars($student_name) . "</strong>,</p>
        
        <div class='alert-box success'>
            <strong>🎓 Congratulations!</strong> Your certificate is ready for download.
        </div>
        
        <div style='text-align: center; margin: 30px 0;'>
            <div style='background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%); 
                        border: 3px solid #f59e0b;
                        border-radius: 12px; 
                        padding: 30px;
                        display: inline-block;
                        max-width: 400px;'>
                <p style='color: #92400e; font-size: 14px; margin-bottom: 10px;'>Certificate of</p>
                <div style='font-size: 24px; font-weight: 700; color: #78350f; margin-bottom: 15px;'>" . htmlspecialchars($certificate_type) . "</div>
                <p style='color: #92400e; font-size: 16px;'>Awarded to</p>
                <div style='font-size: 20px; font-weight: 600; color: #1e293b; margin: 10px 0;'>" . htmlspecialchars($student_name) . "</div>
            </div>
        </div>
        
        <div class='info-box'>
            <h3>📜 Certificate Details</h3>
            <div class='info-row'>
                <span class='info-label'>Certificate ID</span>
                <span class='info-value' style='font-family: monospace;'>" . htmlspecialchars($certificate_id) . "</span>
            </div>
            <div class='info-row'>
                <span class='info-label'>Type</span>
                <span class='info-value'>" . htmlspecialchars($certificate_type) . "</span>
            </div>
            <div class='info-row'>
                <span class='info-label'>Program/Course</span>
                <span class='info-value'>" . htmlspecialchars($course_or_program_name) . "</span>
            </div>
            <div class='info-row'>
                <span class='info-label'>Completion Date</span>
                <span class='info-value'>" . date('F j, Y', strtotime($completion_date)) . "</span>
            </div>
            <div class='info-row'>
                <span class='info-label'>Issued On</span>
                <span class='info-value'>" . date('F j, Y') . "</span>
            </div>
        </div>
        
        <div class='btn-container'>
            <a href='" . ($download_url ? htmlspecialchars($download_url) : SYSTEM_URL . "/student/certificates.php") . "' class='btn-primary'>Download Certificate</a>
        </div>
        
        <p class='content-text' style='text-align: center;'>
            🎉 We are proud of your achievement and wish you continued success in your academic journey!
        </p>
    ";
    
    $body = getEmailTemplate("Certificate Ready", $content, '', '#f59e0b');
    return sendEmail($student_email, $student_name, $subject, $body);
}

/**
 * Send bulk notification to multiple recipients
 */
function sendBulkNotificationEmail($recipients, $subject_text, $notification_title, $notification_body, $action_url = '', $action_text = 'View Details') {
    $uni = getUniversitySettings();
    $subject = htmlspecialchars($subject_text);
    $success_count = 0;
    $fail_count = 0;
    
    foreach ($recipients as $recipient) {
        $content = "
            <p class='greeting'>Dear <strong>" . htmlspecialchars($recipient['name']) . "</strong>,</p>
            
            <div class='info-box'>
                <h3>📢 " . htmlspecialchars($notification_title) . "</h3>
                <p style='color: #374151; line-height: 1.8;'>" . nl2br(htmlspecialchars($notification_body)) . "</p>
            </div>
            
            " . ($action_url ? "<div class='btn-container'>
                <a href='" . htmlspecialchars($action_url) . "' class='btn-primary'>" . htmlspecialchars($action_text) . "</a>
            </div>" : "") . "
        ";
        
        $body = getEmailTemplate("Notification", $content);
        
        if (sendEmail($recipient['email'], $recipient['name'], $subject, $body)) {
            $success_count++;
        } else {
            $fail_count++;
        }
    }
    
    return ['success' => $success_count, 'failed' => $fail_count];
}
