<?php
/**
 * Notification API Endpoint
 * Handles fetching, marking read, and emailing notifications via AJAX
 * 
 * Actions:
 *   GET  ?action=fetch          - Get recent notifications
 *   GET  ?action=count          - Get unread count
 *   POST ?action=mark_read&id=X - Mark one notification as read
 *   POST ?action=mark_all_read  - Mark all as read
 *   POST ?action=email&id=X     - Forward notification to email
 *   POST ?action=click&id=X     - Mark as read + optionally email + return link
 */

require_once '../includes/auth.php';
requireLogin();

require_once '../includes/notifications.php';

header('Content-Type: application/json');

$user_id = $_SESSION['vle_user_id'] ?? 0;
$user_role = $_SESSION['vle_role'] ?? '';

if (!$user_id) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// Auto-generate notifications for lecturer if needed
if ($user_role === 'lecturer') {
    $lecturer_id = $_SESSION['vle_related_id'] ?? '';
    if ($lecturer_id) {
        generateLecturerNotifications($user_id, $lecturer_id);
    }
}

switch ($action) {
    case 'fetch':
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
        $unread_only = isset($_GET['unread_only']) && $_GET['unread_only'] === '1';
        $notifications = getNotifications($user_id, $limit, $unread_only);
        $unread_count = getUnreadNotificationCount($user_id);
        
        // Enrich with display data
        foreach ($notifications as &$n) {
            $n['icon'] = getNotificationBsIcon($n['type']);
            $n['badge_color'] = getNotificationBadgeColor($n['type']);
            $n['time_ago'] = notificationTimeAgo($n['created_at']);
        }
        
        echo json_encode([
            'success' => true,
            'notifications' => $notifications,
            'unread_count' => $unread_count
        ]);
        break;
        
    case 'count':
        $count = getUnreadNotificationCount($user_id);
        echo json_encode(['success' => true, 'unread_count' => $count]);
        break;
        
    case 'mark_read':
        $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
        if ($id && markNotificationRead($id, $user_id)) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to mark as read']);
        }
        break;
        
    case 'mark_all_read':
        markAllNotificationsRead($user_id);
        echo json_encode(['success' => true]);
        break;
        
    case 'email':
        $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
        if (!$id) {
            echo json_encode(['success' => false, 'error' => 'Missing notification ID']);
            break;
        }
        $sent = emailNotification($id, $user_id);
        echo json_encode([
            'success' => $sent,
            'message' => $sent ? 'Notification sent to your email!' : 'Failed to send email. Check SMTP settings.'
        ]);
        break;
    
    case 'click':
        // Click handler: mark as read, optionally email, return link
        $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
        $also_email = isset($_POST['email']) && $_POST['email'] === '1';
        
        if (!$id) {
            echo json_encode(['success' => false, 'error' => 'Missing notification ID']);
            break;
        }
        
        $notification = getNotificationById($id, $user_id);
        if (!$notification) {
            echo json_encode(['success' => false, 'error' => 'Notification not found']);
            break;
        }
        
        // Mark as read
        markNotificationRead($id, $user_id);
        
        // Email if requested
        $email_sent = false;
        if ($also_email) {
            $email_sent = emailNotification($id, $user_id);
        }
        
        echo json_encode([
            'success' => true,
            'link' => $notification['link'] ?? '',
            'email_sent' => $email_sent
        ]);
        break;
        
    default:
        echo json_encode(['success' => false, 'error' => 'Unknown action']);
        break;
}
