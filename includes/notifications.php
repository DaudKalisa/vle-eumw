<?php
/**
 * Notification Helper Functions for VLE System
 * Handles creating, fetching, and managing in-app notifications
 */

require_once __DIR__ . '/config.php';

/**
 * Create a new notification for a user
 * Automatically sends an email notification as well
 * @param int $user_id User ID
 * @param string $type Notification type
 * @param string $title Notification title
 * @param string $message Notification message
 * @param string|null $link Related link
 * @param string|null $related_id Related entity ID
 * @param string|null $related_type Related entity type
 * @param bool $send_email Whether to also send email (default: true)
 * @return int|false Notification ID on success, false on failure
 */
function createNotification($user_id, $type, $title, $message, $link = null, $related_id = null, $related_type = null, $send_email = true) {
    $conn = getDbConnection();
    
    // Check if table exists first
    $table_check = $conn->query("SHOW TABLES LIKE 'vle_notifications'");
    if ($table_check->num_rows === 0) {
        error_log("vle_notifications table does not exist. Run setup_notifications_table.php");
        return false;
    }
    
    $stmt = $conn->prepare("INSERT INTO vle_notifications (user_id, type, title, message, link, related_id, related_type) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("issssss", $user_id, $type, $title, $message, $link, $related_id, $related_type);
    
    if ($stmt->execute()) {
        $notification_id = $conn->insert_id;
        
        // Auto-send email notification
        if ($send_email && $notification_id) {
            try {
                emailNotification($notification_id, $user_id);
            } catch (Exception $e) {
                error_log("Email notification failed for notification #$notification_id: " . $e->getMessage());
                // Don't let email failure block the notification creation
            }
        }
        
        return $notification_id;
    } else {
        error_log("Failed to create notification: " . $conn->error);
        return false;
    }
}

/**
 * Get unread notification count for a user
 */
function getUnreadNotificationCount($user_id) {
    $conn = getDbConnection();
    
    $table_check = $conn->query("SHOW TABLES LIKE 'vle_notifications'");
    if ($table_check->num_rows === 0) return 0;
    
    $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM vle_notifications WHERE user_id = ? AND is_read = 0");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    return (int)$result['cnt'];
}

/**
 * Get recent notifications for a user
 */
function getNotifications($user_id, $limit = 20, $unread_only = false) {
    $conn = getDbConnection();
    
    $table_check = $conn->query("SHOW TABLES LIKE 'vle_notifications'");
    if ($table_check->num_rows === 0) return [];
    
    $sql = "SELECT * FROM vle_notifications WHERE user_id = ?";
    if ($unread_only) {
        $sql .= " AND is_read = 0";
    }
    $sql .= " ORDER BY created_at DESC LIMIT ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $user_id, $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $notifications = [];
    while ($row = $result->fetch_assoc()) {
        $notifications[] = $row;
    }
    return $notifications;
}

/**
 * Mark a notification as read
 */
function markNotificationRead($notification_id, $user_id) {
    $conn = getDbConnection();
    $stmt = $conn->prepare("UPDATE vle_notifications SET is_read = 1, read_at = NOW() WHERE notification_id = ? AND user_id = ?");
    $stmt->bind_param("ii", $notification_id, $user_id);
    return $stmt->execute();
}

/**
 * Mark all notifications as read for a user
 */
function markAllNotificationsRead($user_id) {
    $conn = getDbConnection();
    $stmt = $conn->prepare("UPDATE vle_notifications SET is_read = 1, read_at = NOW() WHERE user_id = ? AND is_read = 0");
    $stmt->bind_param("i", $user_id);
    return $stmt->execute();
}

/**
 * Get a single notification by ID
 */
function getNotificationById($notification_id, $user_id) {
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT * FROM vle_notifications WHERE notification_id = ? AND user_id = ?");
    $stmt->bind_param("ii", $notification_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc();
}

/**
 * Mark a notification as emailed
 */
function markNotificationEmailed($notification_id) {
    $conn = getDbConnection();
    $stmt = $conn->prepare("UPDATE vle_notifications SET is_emailed = 1 WHERE notification_id = ?");
    $stmt->bind_param("i", $notification_id);
    return $stmt->execute();
}

/**
 * Forward a notification to user's email
 */
function emailNotification($notification_id, $user_id) {
    require_once __DIR__ . '/email.php';
    
    $notification = getNotificationById($notification_id, $user_id);
    if (!$notification) return false;
    
    // Get user email
    $conn = getDbConnection();
    $stmt = $conn->prepare("
        SELECT u.*, 
               COALESCE(l.email, s.email, st.email, u.email) as user_email,
               COALESCE(l.full_name, s.full_name, st.full_name, u.username) as display_name
        FROM users u
        LEFT JOIN lecturers l ON u.related_lecturer_id = l.lecturer_id
        LEFT JOIN students s ON u.related_student_id = s.student_id
        LEFT JOIN administrative_staff st ON u.related_staff_id = st.staff_id
        WHERE u.user_id = ?
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    
    if (!$user || empty($user['user_email'])) {
        error_log("Cannot email notification - user email not found for user_id: $user_id");
        return false;
    }
    
    // Build notification link
    $link_html = '';
    if (!empty($notification['link'])) {
        $full_url = $notification['link'];
        // Make relative links absolute
        if (strpos($full_url, 'http') !== 0) {
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $base = defined('SITE_URL') ? rtrim(SITE_URL, '/') : $protocol . '://' . $host . '/vle-eumw';
            $full_url = $base . '/' . ltrim($full_url, '/');
        }
        $link_html = "<p><a href='" . htmlspecialchars($full_url) . "' style='display:inline-block;padding:12px 24px;background:#2563eb;color:#fff;text-decoration:none;border-radius:6px;font-weight:600;'>View Details</a></p>";
    }
    
    // Build email body
    $icon = getNotificationIcon($notification['type']);
    $content = "
        <h2 style='color:#1e3a5f;margin-bottom:10px;'>{$icon} {$notification['title']}</h2>
        <p style='font-size:15px;color:#333;line-height:1.6;'>" . nl2br(htmlspecialchars($notification['message'])) . "</p>
        {$link_html}
        <p style='font-size:13px;color:#888;margin-top:20px;'>This notification was sent from your VLE dashboard on " . date('M j, Y \a\t g:i A') . ".</p>
    ";
    
    $body = getEmailTemplate('VLE Notification: ' . $notification['title'], $content);
    $subject = 'VLE Notification: ' . $notification['title'];
    
    $sent = sendEmail($user['user_email'], $user['display_name'], $subject, $body);
    
    if ($sent) {
        markNotificationEmailed($notification_id);
    }
    
    return $sent;
}

/**
 * Get styled HTML icon badge for notification type (for email/display)
 */
function getNotificationIcon($type) {
    // Each entry: [symbol, background_color]
    $icons = [
        'submission'   => ['&#9998;', '#6366f1'],   // ✎ pencil - indigo
        'message'      => ['&#9993;', '#0891b2'],   // ✉ envelope - cyan
        'enrollment'   => ['&#9786;', '#6366f1'],   // ☺ person - indigo
        'announcement' => ['&#9654;', '#f59e0b'],   // ▶ announce - amber
        'grade'        => ['&#9632;', '#0ea5e9'],   // ■ chart - sky
        'forum'        => ['&#9993;', '#6366f1'],   // ✉ discussion - indigo
        'finance'      => ['$', '#10b981'],          // $ money - green
        'content'      => ['&#9776;', '#3b82f6'],   // ☰ content - blue
        'system'       => ['&#9830;', '#f59e0b'],   // ♦ bell - amber
        'exam'         => ['&#9776;', '#3b82f6'],   // ☰ exam - blue
    ];

    $default = ['&#9830;', '#f59e0b']; // ♦ default bell - amber
    [$symbol, $color] = $icons[$type] ?? $default;

    return '<span style="display:inline-block;width:20px;height:20px;background:' . $color
        . ';color:#ffffff;border-radius:4px;text-align:center;font-size:12px;line-height:20px;'
        . 'margin-right:5px;vertical-align:middle;font-family:Arial,sans-serif;">'
        . $symbol . '</span>';
}

/**
 * Get Bootstrap icon class for notification type
 */
function getNotificationBsIcon($type) {
    $icons = [
        'submission'   => 'bi-file-earmark-check',
        'message'      => 'bi-chat-dots',
        'enrollment'   => 'bi-person-plus',
        'announcement' => 'bi-megaphone',
        'grade'        => 'bi-bar-chart',
        'forum'        => 'bi-chat-left-text',
        'finance'      => 'bi-cash-coin',
        'content'      => 'bi-folder-plus',
        'system'       => 'bi-bell',
        'exam'         => 'bi-pencil-square',
    ];
    return $icons[$type] ?? 'bi-bell';
}

/**
 * Get badge color for notification type
 */
function getNotificationBadgeColor($type) {
    $colors = [
        'submission'   => 'primary',
        'message'      => 'info',
        'enrollment'   => 'success',
        'announcement' => 'warning',
        'grade'        => 'secondary',
        'forum'        => 'info',
        'finance'      => 'success',
        'content'      => 'primary',
        'system'       => 'danger',
        'exam'         => 'dark',
    ];
    return $colors[$type] ?? 'secondary';
}

/**
 * Generate notifications for a lecturer from existing data
 * Called to populate notifications from submissions, messages, enrollments etc.
 */
function generateLecturerNotifications($lecturer_user_id, $lecturer_id) {
    $conn = getDbConnection();
    
    $table_check = $conn->query("SHOW TABLES LIKE 'vle_notifications'");
    if ($table_check->num_rows === 0) return;
    
    // 1. New assignment submissions (ungraded)
    $sql = "SELECT vs.submission_id, vs.student_id, vs.submission_date, va.title as assignment_title, 
                   va.course_id, s.full_name as student_name, vc.course_name
            FROM vle_submissions vs
            JOIN vle_assignments va ON vs.assignment_id = va.assignment_id
            JOIN vle_courses vc ON va.course_id = vc.course_id
            JOIN students s ON vs.student_id = s.student_id
            WHERE vc.lecturer_id = ?
            AND (vs.score IS NULL)
            AND vs.submission_id NOT IN (
                SELECT related_id FROM vle_notifications 
                WHERE user_id = ? AND related_type = 'submission' AND related_id IS NOT NULL
            )
            ORDER BY vs.submission_date DESC
            LIMIT 50";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("si", $lecturer_id, $lecturer_user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        createNotification(
            $lecturer_user_id,
            'submission',
            'New Submission: ' . $row['assignment_title'],
            $row['student_name'] . ' submitted work for "' . $row['assignment_title'] . '" in ' . $row['course_name'],
            'lecturer/gradebook.php?course_id=' . $row['course_id'],
            $row['submission_id'],
            'submission',
            false // Skip individual emails for batch-generated notifications
        );
    }
    
    // 2. Unread messages
    // sender_id is varchar; resolve sender display name via COALESCE across role tables
    $sql = "SELECT m.message_id, m.subject, m.sender_id, m.sender_type, m.sent_date,
                   COALESCE(s.full_name, l.full_name, ast.full_name, m.sender_id) as sender_name
            FROM vle_messages m
            LEFT JOIN students s ON m.sender_type = 'student' AND m.sender_id = s.student_id
            LEFT JOIN lecturers l ON m.sender_type = 'lecturer' AND m.sender_id = l.lecturer_id
            LEFT JOIN administrative_staff ast ON m.sender_type = 'admin' AND m.sender_id = ast.staff_id
            WHERE m.recipient_id = ?
            AND m.is_read = 0
            AND m.message_id NOT IN (
                SELECT related_id FROM vle_notifications 
                WHERE user_id = ? AND related_type = 'message' AND related_id IS NOT NULL
            )
            ORDER BY m.sent_date DESC
            LIMIT 20";
    $stmt = $conn->prepare($sql);
    $lec_id_str = (string)$lecturer_id;
    $stmt->bind_param("si", $lec_id_str, $lecturer_user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        createNotification(
            $lecturer_user_id,
            'message',
            'New Message: ' . ($row['subject'] ?: 'No Subject'),
            'From: ' . ($row['sender_name'] ?? 'Unknown'),
            'lecturer/messages.php?message_id=' . $row['message_id'],
            $row['message_id'],
            'message',
            false // Skip individual emails for batch-generated notifications
        );
    }
    
    // 3. Recent enrollments in lecturer's courses
    $sql = "SELECT ve.enrollment_id, ve.student_id, ve.enrollment_date, s.full_name as student_name,
                   vc.course_name, vc.course_id
            FROM vle_enrollments ve
            JOIN vle_courses vc ON ve.course_id = vc.course_id
            JOIN students s ON ve.student_id = s.student_id
            WHERE vc.lecturer_id = ?
            AND ve.enrollment_date >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            AND ve.enrollment_id NOT IN (
                SELECT related_id FROM vle_notifications 
                WHERE user_id = ? AND related_type = 'enrollment' AND related_id IS NOT NULL
            )
            ORDER BY ve.enrollment_date DESC
            LIMIT 20";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("si", $lecturer_id, $lecturer_user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        createNotification(
            $lecturer_user_id,
            'enrollment',
            'New Student Enrolled',
            $row['student_name'] . ' enrolled in ' . $row['course_name'],
            'lecturer/manage_content.php?course_id=' . $row['course_id'],
            $row['enrollment_id'],
            'enrollment',
            false // Skip individual emails for batch-generated notifications
        );
    }
}

/**
 * Time ago helper
 */
function notificationTimeAgo($datetime) {
    $now = new DateTime();
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);
    
    if ($diff->y > 0) return $diff->y . 'y ago';
    if ($diff->m > 0) return $diff->m . 'mo ago';
    if ($diff->d > 0) return $diff->d . 'd ago';
    if ($diff->h > 0) return $diff->h . 'h ago';
    if ($diff->i > 0) return $diff->i . 'm ago';
    return 'Just now';
}
