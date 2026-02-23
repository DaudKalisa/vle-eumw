<?php
// email.php - Email configuration and helper functions for VLE System

// Email configuration - Update these with your SMTP settings
define('SMTP_HOST', 'smtp.gmail.com'); // e.g., smtp.gmail.com
define('SMTP_PORT', 587);
define('SMTP_USERNAME', 'your-email@gmail.com'); // Your email address
define('SMTP_PASSWORD', 'your-app-password'); // Your app password
define('SMTP_FROM_EMAIL', 'your-email@gmail.com');
define('SMTP_FROM_NAME', 'VLE System');
define('SMTP_ENCRYPTION', 'tls'); // 'tls' or 'ssl'

// System URL - Update with your actual URL
define('SYSTEM_URL', 'http://localhost/vle_system');

/**
 * Send email using PHPMailer
 */
function sendEmail($to_email, $to_name, $subject, $body, $cc_email = null, $cc_name = null) {
    require_once __DIR__ . '/../vendor/autoload.php';
    
    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    
    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USERNAME;
        $mail->Password = SMTP_PASSWORD;
        $mail->SMTPSecure = SMTP_ENCRYPTION;
        $mail->Port = SMTP_PORT;
        
        // Recipients
        $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        $mail->addAddress($to_email, $to_name);
        
        // CC if provided
        if ($cc_email) {
            $mail->addCC($cc_email, $cc_name);
        }
        
        // Content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $body;
        $mail->AltBody = strip_tags($body);
        
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Email sending failed: " . $mail->ErrorInfo);
        return false;
    }
}

/**
 * Send assignment submission notification to lecturer
 */
function sendAssignmentSubmissionEmail($student_email, $student_name, $lecturer_email, $lecturer_name, $assignment_title, $course_name, $submission_id) {
    $subject = "New Assignment Submission - $course_name";
    
    $body = "
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background-color: #007bff; color: white; padding: 20px; text-align: center; }
            .content { background-color: #f8f9fa; padding: 20px; margin: 20px 0; }
            .button { display: inline-block; padding: 10px 20px; background-color: #28a745; color: white; text-decoration: none; border-radius: 5px; }
            .footer { text-align: center; color: #6c757d; font-size: 12px; margin-top: 20px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h2>New Assignment Submission</h2>
            </div>
            <div class='content'>
                <p>Dear $lecturer_name,</p>
                <p><strong>$student_name</strong> has submitted an assignment:</p>
                <ul>
                    <li><strong>Course:</strong> $course_name</li>
                    <li><strong>Assignment:</strong> $assignment_title</li>
                    <li><strong>Submitted by:</strong> $student_name ($student_email)</li>
                    <li><strong>Submission Date:</strong> " . date('F j, Y g:i A') . "</li>
                </ul>
                <p style='text-align: center; margin: 30px 0;'>
                    <a href='" . SYSTEM_URL . "/lecturer/gradebook.php?submission_id=$submission_id' class='button'>Grade Submission</a>
                </p>
            </div>
            <div class='footer'>
                <p>This is an automated email from VLE System. Please do not reply.</p>
            </div>
        </div>
    </body>
    </html>
    ";
    
    return sendEmail($lecturer_email, $lecturer_name, $subject, $body, $student_email, $student_name);
}

/**
 * Send grade notification to student
 */
function sendGradeNotificationEmail($student_email, $student_name, $lecturer_email, $lecturer_name, $assignment_title, $course_name, $score, $grade_letter, $feedback, $course_id) {
    $subject = "Assignment Graded - $course_name";
    
    $status_color = $score >= 50 ? '#28a745' : '#dc3545';
    $status_text = $score >= 50 ? 'PASS' : 'FAIL';
    
    $body = "
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background-color: #007bff; color: white; padding: 20px; text-align: center; }
            .content { background-color: #f8f9fa; padding: 20px; margin: 20px 0; }
            .grade-box { background-color: white; border: 2px solid $status_color; padding: 20px; text-align: center; margin: 20px 0; }
            .grade-score { font-size: 48px; font-weight: bold; color: $status_color; }
            .button { display: inline-block; padding: 10px 20px; background-color: #007bff; color: white; text-decoration: none; border-radius: 5px; }
            .footer { text-align: center; color: #6c757d; font-size: 12px; margin-top: 20px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h2>Your Assignment Has Been Graded</h2>
            </div>
            <div class='content'>
                <p>Dear $student_name,</p>
                <p>Your assignment has been graded by <strong>$lecturer_name</strong>:</p>
                <ul>
                    <li><strong>Course:</strong> $course_name</li>
                    <li><strong>Assignment:</strong> $assignment_title</li>
                </ul>
                <div class='grade-box'>
                    <div class='grade-score'>$grade_letter</div>
                    <div style='font-size: 24px; margin: 10px 0;'>$score%</div>
                    <div style='font-weight: bold; color: $status_color;'>$status_text</div>
                </div>
                " . ($feedback ? "<p><strong>Feedback:</strong><br>$feedback</p>" : "") . "
                <p style='text-align: center; margin: 30px 0;'>
                    <a href='" . SYSTEM_URL . "/student/dashboard.php?course_id=$course_id&view=grades' class='button'>View Full Grades</a>
                </p>
            </div>
            <div class='footer'>
                <p>This is an automated email from VLE System. Please do not reply.</p>
            </div>
        </div>
    </body>
    </html>
    ";
    
    return sendEmail($student_email, $student_name, $subject, $body, $lecturer_email, $lecturer_name);
}

/**
 * Send message notification
 */
function sendMessageNotificationEmail($recipient_email, $recipient_name, $sender_email, $sender_name, $subject_text, $message_content, $message_id, $recipient_type) {
    $subject = "New Message: $subject_text";
    
    $view_url = SYSTEM_URL . "/$recipient_type/messages.php?message_id=$message_id";
    
    $body = "
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background-color: #17a2b8; color: white; padding: 20px; text-align: center; }
            .content { background-color: #f8f9fa; padding: 20px; margin: 20px 0; }
            .message-box { background-color: white; border-left: 4px solid #17a2b8; padding: 15px; margin: 20px 0; }
            .button { display: inline-block; padding: 10px 20px; background-color: #17a2b8; color: white; text-decoration: none; border-radius: 5px; }
            .footer { text-align: center; color: #6c757d; font-size: 12px; margin-top: 20px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h2>New Message Received</h2>
            </div>
            <div class='content'>
                <p>Dear $recipient_name,</p>
                <p>You have received a new message from <strong>$sender_name</strong> ($sender_email):</p>
                <div class='message-box'>
                    <h3>$subject_text</h3>
                    <p>" . nl2br(htmlspecialchars(substr($message_content, 0, 200))) . (strlen($message_content) > 200 ? '...' : '') . "</p>
                </div>
                <p style='text-align: center; margin: 30px 0;'>
                    <a href='$view_url' class='button'>View & Reply</a>
                </p>
            </div>
            <div class='footer'>
                <p>This is an automated email from VLE System. Please do not reply.</p>
            </div>
        </div>
    </body>
    </html>
    ";
    
    return sendEmail($recipient_email, $recipient_name, $subject, $body, $sender_email, $sender_name);
}

/**
 * Send announcement to all students in a course
 */
function sendAnnouncementEmail($student_email, $student_name, $lecturer_name, $course_name, $announcement_title, $announcement_content, $course_id) {
    $subject = "Course Announcement - $course_name";
    
    $body = "
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background-color: #ffc107; color: #333; padding: 20px; text-align: center; }
            .content { background-color: #f8f9fa; padding: 20px; margin: 20px 0; }
            .announcement-box { background-color: white; border: 2px solid #ffc107; padding: 20px; margin: 20px 0; }
            .button { display: inline-block; padding: 10px 20px; background-color: #007bff; color: white; text-decoration: none; border-radius: 5px; }
            .footer { text-align: center; color: #6c757d; font-size: 12px; margin-top: 20px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h2>📢 Course Announcement</h2>
            </div>
            <div class='content'>
                <p>Dear $student_name,</p>
                <p><strong>$lecturer_name</strong> has posted an announcement for <strong>$course_name</strong>:</p>
                <div class='announcement-box'>
                    <h3>$announcement_title</h3>
                    <p>$announcement_content</p>
                </div>
                <p style='text-align: center; margin: 30px 0;'>
                    <a href='" . SYSTEM_URL . "/student/dashboard.php?course_id=$course_id' class='button'>View Course</a>
                </p>
            </div>
            <div class='footer'>
                <p>This is an automated email from VLE System. Please do not reply.</p>
            </div>
        </div>
    </body>
    </html>
    ";
    
    return sendEmail($student_email, $student_name, $subject, $body);
}

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
    $subject = "Email Verification - VLE System";
    
    $body = "
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background-color: #28a745; color: white; padding: 20px; text-align: center; }
            .content { background-color: #f8f9fa; padding: 20px; margin: 20px 0; }
            .code-box { background-color: white; border: 2px solid #28a745; padding: 20px; text-align: center; margin: 20px 0; }
            .code { font-size: 32px; font-weight: bold; color: #28a745; letter-spacing: 5px; }
            .footer { text-align: center; color: #6c757d; font-size: 12px; margin-top: 20px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h2>Verify Your Email Address</h2>
            </div>
            <div class='content'>
                <p>Dear $name,</p>
                <p>Please use the verification code below to verify your email address:</p>
                <div class='code-box'>
                    <div class='code'>$code</div>
                </div>
                <p>This code will expire in 30 minutes.</p>
                <p>If you did not request this verification, please ignore this email.</p>
            </div>
            <div class='footer'>
                <p>This is an automated email from VLE System. Please do not reply.</p>
            </div>
        </div>
    </body>
    </html>
    ";
    
    return sendEmail($email, $name, $subject, $body);
}
