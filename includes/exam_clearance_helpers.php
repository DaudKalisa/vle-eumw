<?php
/**
 * Exam Clearance Helper Functions
 * Email notifications and activity logging for the exam clearance system
 */

require_once __DIR__ . '/email.php';

/**
 * Log an exam clearance activity
 */
function logExamClearanceActivity($conn, $clearance_id, $action, $actor_type = 'system', $actor_id = null, $actor_name = null, $details = null) {
    $stmt = $conn->prepare("INSERT INTO exam_clearance_activity_log (clearance_id, action, actor_type, actor_id, actor_name, details) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("ississ", $clearance_id, $action, $actor_type, $actor_id, $actor_name, $details);
    $stmt->execute();
}

/**
 * Send email notification for exam clearance events
 */
function sendExamClearanceEmail($to_email, $to_name, $subject, $content_html) {
    if (!function_exists('isEmailEnabled') || !isEmailEnabled()) {
        return false;
    }
    $body = getEmailTemplate($subject, $content_html, 'This is an automated notification from the Examination Clearance System.', '#10b981');
    return sendEmail($to_email, $to_name, $subject, $body);
}

/**
 * Notify student: Application submitted successfully
 */
function notifyStudentApplicationSubmitted($conn, $student_email, $student_name, $student_id, $program, $invoiced_amount) {
    $amount_fmt = number_format($invoiced_amount, 2);
    $content = "
        <p class='greeting'>Dear {$student_name},</p>
        <p class='content-text'>Your examination clearance application has been <strong>submitted successfully</strong>.</p>
        <div class='info-box'>
            <h3>Application Details</h3>
            <div class='info-row'><span class='info-label'>Student ID</span><span class='info-value'>{$student_id}</span></div>
            <div class='info-row'><span class='info-label'>Program</span><span class='info-value'>{$program}</span></div>
            <div class='info-row'><span class='info-label'>Invoiced Amount</span><span class='info-value'>MWK {$amount_fmt}</span></div>
            <div class='info-row'><span class='info-label'>Status</span><span class='info-value'>Invoiced — Awaiting proof of payment</span></div>
        </div>
        <p class='content-text'>Please log in to the Student Portal and upload your proof of payment (Registration Fee and Tuition Fee receipts) to proceed with your clearance.</p>
    ";
    return sendExamClearanceEmail($student_email, $student_name, 'Exam Clearance Application Submitted', $content);
}

/**
 * Notify finance: New application received
 */
function notifyFinanceNewApplication($conn, $student_name, $student_id, $program_type) {
    // Get finance users — join finance_users for full_name since users table doesn't have it
    $result = $conn->query("SELECT u.email, COALESCE(f.full_name, u.username) as full_name FROM users u LEFT JOIN finance_users f ON u.related_finance_id = f.finance_id WHERE u.role = 'finance' AND u.is_active = 1");
    if (!$result) return;
    while ($finance = $result->fetch_assoc()) {
        if (empty($finance['email'])) continue;
        $content = "
            <p class='greeting'>Dear {$finance['full_name']},</p>
            <p class='content-text'>A new examination clearance application has been submitted and requires your attention.</p>
            <div class='info-box'>
                <h3>Application Details</h3>
                <div class='info-row'><span class='info-label'>Student</span><span class='info-value'>{$student_name}</span></div>
                <div class='info-row'><span class='info-label'>Student ID</span><span class='info-value'>{$student_id}</span></div>
                <div class='info-row'><span class='info-label'>Program Type</span><span class='info-value'>" . ucfirst($program_type) . "</span></div>
            </div>
            <p class='content-text'>Please review this application in the Finance Portal.</p>
        ";
        sendExamClearanceEmail($finance['email'], $finance['full_name'], 'New Exam Clearance Application: ' . $student_name, $content);
    }
}

/**
 * Notify student: Proof of payment submitted
 */
function notifyStudentProofSubmitted($conn, $student_email, $student_name, $amount) {
    $amount_fmt = number_format($amount, 2);
    $content = "
        <p class='greeting'>Dear {$student_name},</p>
        <p class='content-text'>Your proof of payment has been <strong>submitted successfully</strong> and is now under review by the Finance Office.</p>
        <div class='info-box'>
            <h3>Payment Details</h3>
            <div class='info-row'><span class='info-label'>Amount</span><span class='info-value'>MWK {$amount_fmt}</span></div>
            <div class='info-row'><span class='info-label'>Status</span><span class='info-value'>Under Review</span></div>
        </div>
        <p class='content-text'>You will be notified once the Finance Office reviews your payment.</p>
    ";
    return sendExamClearanceEmail($student_email, $student_name, 'Proof of Payment Submitted — Exam Clearance', $content);
}

/**
 * Notify finance: Proof of payment submitted by student
 */
function notifyFinanceProofSubmitted($conn, $student_name, $student_id, $amount) {
    $amount_fmt = number_format($amount, 2);
    $result = $conn->query("SELECT u.email, COALESCE(f.full_name, u.username) as full_name FROM users u LEFT JOIN finance_users f ON u.related_finance_id = f.finance_id WHERE u.role = 'finance' AND u.is_active = 1");
    if (!$result) return;
    while ($finance = $result->fetch_assoc()) {
        if (empty($finance['email'])) continue;
        $content = "
            <p class='greeting'>Dear {$finance['full_name']},</p>
            <p class='content-text'>A student has submitted proof of payment for exam clearance and requires your review.</p>
            <div class='info-box'>
                <h3>Details</h3>
                <div class='info-row'><span class='info-label'>Student</span><span class='info-value'>{$student_name}</span></div>
                <div class='info-row'><span class='info-label'>Student ID</span><span class='info-value'>{$student_id}</span></div>
                <div class='info-row'><span class='info-label'>Amount Claimed</span><span class='info-value'>MWK {$amount_fmt}</span></div>
            </div>
            <p class='content-text'>Please review this payment in the Finance Portal.</p>
        ";
        sendExamClearanceEmail($finance['email'], $finance['full_name'], 'Proof of Payment Received: ' . $student_name, $content);
    }
}

/**
 * Notify student: Finance requested proof of payment
 */
function notifyStudentProofRequested($conn, $student_email, $student_name, $finance_notes = '') {
    $notes_html = $finance_notes ? "<p class='content-text'><strong>Finance Officer Notes:</strong> " . htmlspecialchars($finance_notes) . "</p>" : '';
    $content = "
        <p class='greeting'>Dear {$student_name},</p>
        <p class='content-text'>The Finance Office has reviewed your examination clearance application and is <strong>requesting proof of payment</strong>.</p>
        {$notes_html}
        <p class='content-text'>Please log in to the Student Portal and upload your proof of payment (Registration Fee and Tuition Fee receipts) as soon as possible.</p>
    ";
    return sendExamClearanceEmail($student_email, $student_name, 'Action Required: Proof of Payment Requested — Exam Clearance', $content);
}

/**
 * Notify student: Clearance approved
 */
function notifyStudentCleared($conn, $student_email, $student_name, $certificate_number, $cleared_by_name) {
    $content = "
        <p class='greeting'>Dear {$student_name},</p>
        <p class='content-text'>Congratulations! Your examination clearance has been <strong>approved</strong>. You are now cleared to sit for examinations.</p>
        <div class='info-box'>
            <h3>Clearance Certificate</h3>
            <div class='info-row'><span class='info-label'>Certificate Number</span><span class='info-value'>{$certificate_number}</span></div>
            <div class='info-row'><span class='info-label'>Approved By</span><span class='info-value'>{$cleared_by_name}</span></div>
            <div class='info-row'><span class='info-label'>Status</span><span class='info-value'>CLEARED</span></div>
        </div>
        <p class='content-text'>You may view and print your certificate from the Student Portal.</p>
    ";
    return sendExamClearanceEmail($student_email, $student_name, 'Exam Clearance Approved — Certificate ' . $certificate_number, $content);
}

/**
 * Notify student: Clearance rejected
 */
function notifyStudentRejected($conn, $student_email, $student_name, $finance_notes = '') {
    $notes_html = $finance_notes ? "<p class='content-text'><strong>Reason:</strong> " . htmlspecialchars($finance_notes) . "</p>" : '';
    $content = "
        <p class='greeting'>Dear {$student_name},</p>
        <p class='content-text'>Unfortunately, your examination clearance application has been <strong>rejected</strong>.</p>
        {$notes_html}
        <p class='content-text'>If you believe this is an error, please contact the Finance Office for assistance. You may re-apply from the Student Portal.</p>
    ";
    return sendExamClearanceEmail($student_email, $student_name, 'Exam Clearance Application Rejected', $content);
}

/**
 * Notify student: Payment approved/rejected by finance
 */
function notifyStudentPaymentReviewed($conn, $student_email, $student_name, $amount, $decision, $review_notes = '') {
    $amount_fmt = number_format($amount, 2);
    $status_text = $decision === 'approved' ? 'approved' : 'rejected';
    $notes_html = $review_notes ? "<p class='content-text'><strong>Finance Notes:</strong> " . htmlspecialchars($review_notes) . "</p>" : '';
    $content = "
        <p class='greeting'>Dear {$student_name},</p>
        <p class='content-text'>Your payment submission of <strong>MWK {$amount_fmt}</strong> has been <strong>{$status_text}</strong> by the Finance Office.</p>
        {$notes_html}
    ";
    if ($decision === 'rejected') {
        $content .= "<p class='content-text'>Please upload a corrected proof of payment if applicable.</p>";
    }
    $subject = 'Payment ' . ucfirst($status_text) . ' — Exam Clearance';
    return sendExamClearanceEmail($student_email, $student_name, $subject, $content);
}
