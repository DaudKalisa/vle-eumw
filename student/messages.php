<?php
/**
 * Student Messaging System
 * Modern messaging interface inspired by UNICAF VLE and leading university platforms
 */
require_once '../includes/auth.php';
require_once '../includes/email.php';
requireLogin();
requireRole(['student']);

$conn = getDbConnection();
$student_id = $_SESSION['vle_related_id'];
$user = getCurrentUser();

// Get student details
$student_stmt = $conn->prepare("SELECT full_name, email, program, year_of_study FROM students WHERE student_id = ?");
$student_stmt->bind_param("s", $student_id);
$student_stmt->execute();
$student_data = $student_stmt->get_result()->fetch_assoc();

// AJAX Handlers
if (isset($_GET['ajax']) || isset($_POST['ajax'])) {
    header('Content-Type: application/json');
    $action = $_GET['action'] ?? $_POST['action'] ?? '';
    
    // Fetch conversations list
    if ($action === 'get_conversations') {
        $conversations = [];
        $query = "
            SELECT 
                m.message_id,
                m.sender_type,
                m.sender_id,
                m.recipient_type,
                m.recipient_id,
                m.subject,
                m.message,
                m.sent_date,
                m.is_read,
                CASE 
                    WHEN m.sender_type = 'student' AND m.sender_id = ? THEN m.recipient_type
                    ELSE m.sender_type
                END as partner_type,
                CASE 
                    WHEN m.sender_type = 'student' AND m.sender_id = ? THEN m.recipient_id
                    ELSE m.sender_id
                END as partner_id
            FROM vle_messages m
            WHERE (m.sender_type = 'student' AND m.sender_id = ?)
               OR (m.recipient_type = 'student' AND m.recipient_id = ?)
            ORDER BY m.sent_date DESC
        ";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("ssss", $student_id, $student_id, $student_id, $student_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $seen_partners = [];
        while ($row = $result->fetch_assoc()) {
            $partner_key = $row['partner_type'] . '_' . $row['partner_id'];
            if (!isset($seen_partners[$partner_key])) {
                // Get partner name
                $partner_name = 'Unknown';
                $partner_avatar = '';
                if ($row['partner_type'] === 'lecturer') {
                    $p = $conn->prepare("SELECT full_name FROM lecturers WHERE lecturer_id = ?");
                    $p->bind_param("s", $row['partner_id']);
                    $p->execute();
                    $pr = $p->get_result()->fetch_assoc();
                    $partner_name = $pr['full_name'] ?? 'Lecturer';
                } elseif ($row['partner_type'] === 'student') {
                    $p = $conn->prepare("SELECT full_name FROM students WHERE student_id = ?");
                    $p->bind_param("s", $row['partner_id']);
                    $p->execute();
                    $pr = $p->get_result()->fetch_assoc();
                    $partner_name = $pr['full_name'] ?? 'Student';
                } elseif ($row['partner_type'] === 'admin') {
                    $p = $conn->prepare("SELECT username FROM users WHERE user_id = ?");
                    $p->bind_param("i", $row['partner_id']);
                    $p->execute();
                    $pr = $p->get_result()->fetch_assoc();
                    $partner_name = $pr['username'] ?? 'Administrator';
                }
                
                // Count unread
                $unread_stmt = $conn->prepare("
                    SELECT COUNT(*) as unread FROM vle_messages 
                    WHERE sender_type = ? AND sender_id = ? 
                    AND recipient_type = 'student' AND recipient_id = ? 
                    AND is_read = 0
                ");
                $unread_stmt->bind_param("sss", $row['partner_type'], $row['partner_id'], $student_id);
                $unread_stmt->execute();
                $unread_count = $unread_stmt->get_result()->fetch_assoc()['unread'] ?? 0;
                
                $conversations[] = [
                    'partner_type' => $row['partner_type'],
                    'partner_id' => $row['partner_id'],
                    'partner_name' => $partner_name,
                    'last_message' => mb_substr($row['message'], 0, 50) . (strlen($row['message']) > 50 ? '...' : ''),
                    'last_date' => $row['sent_date'],
                    'unread' => $unread_count,
                    'is_sender' => ($row['sender_type'] === 'student' && $row['sender_id'] === $student_id)
                ];
                $seen_partners[$partner_key] = true;
            }
        }
        echo json_encode(['status' => 'success', 'conversations' => $conversations]);
        exit;
    }
    
    // Fetch messages for a conversation
    if ($action === 'get_messages') {
        $partner_type = $_GET['partner_type'] ?? '';
        $partner_id = $_GET['partner_id'] ?? '';
        
        if (!$partner_type || !$partner_id) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid parameters']);
            exit;
        }
        
        // Mark messages as read
        $mark_read = $conn->prepare("
            UPDATE vle_messages SET is_read = 1 
            WHERE sender_type = ? AND sender_id = ? 
            AND recipient_type = 'student' AND recipient_id = ?
        ");
        $mark_read->bind_param("sss", $partner_type, $partner_id, $student_id);
        $mark_read->execute();
        
        // Get messages
        $stmt = $conn->prepare("
            SELECT m.*, 
                CASE WHEN m.sender_type = 'student' AND m.sender_id = ? THEN 1 ELSE 0 END as is_mine
            FROM vle_messages m
            WHERE (m.sender_type = 'student' AND m.sender_id = ? AND m.recipient_type = ? AND m.recipient_id = ?)
               OR (m.sender_type = ? AND m.sender_id = ? AND m.recipient_type = 'student' AND m.recipient_id = ?)
            ORDER BY m.sent_date ASC
        ");
        $stmt->bind_param("sssssss", $student_id, $student_id, $partner_type, $partner_id, $partner_type, $partner_id, $student_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $messages = [];
        while ($row = $result->fetch_assoc()) {
            $messages[] = [
                'id' => $row['message_id'],
                'subject' => $row['subject'],
                'message' => $row['message'],
                'date' => $row['sent_date'],
                'is_mine' => (bool)$row['is_mine'],
                'is_read' => (bool)$row['is_read']
            ];
        }
        
        echo json_encode(['status' => 'success', 'messages' => $messages]);
        exit;
    }
    
    // Send message
    if ($action === 'send_message') {
        $partner_type = $_POST['partner_type'] ?? '';
        $partner_id = $_POST['partner_id'] ?? '';
        $subject = trim($_POST['subject'] ?? '');
        $message = trim($_POST['message'] ?? '');
        
        if (!$partner_type || !$partner_id || !$message) {
            echo json_encode(['status' => 'error', 'message' => 'Please fill in all required fields']);
            exit;
        }
        
        $stmt = $conn->prepare("
            INSERT INTO vle_messages (sender_type, sender_id, recipient_type, recipient_id, subject, message, sent_date) 
            VALUES ('student', ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->bind_param("sssss", $student_id, $partner_type, $partner_id, $subject, $message);
        
        if ($stmt->execute()) {
            $message_id = $conn->insert_id;
            
            // Send email notification
            $recipient_email = '';
            $recipient_name = '';
            
            if ($partner_type === 'lecturer') {
                $p = $conn->prepare("SELECT full_name, email FROM lecturers WHERE lecturer_id = ?");
                $p->bind_param("s", $partner_id);
                $p->execute();
                $pr = $p->get_result()->fetch_assoc();
                $recipient_email = $pr['email'] ?? '';
                $recipient_name = $pr['full_name'] ?? '';
            } elseif ($partner_type === 'student') {
                $p = $conn->prepare("SELECT full_name, email FROM students WHERE student_id = ?");
                $p->bind_param("s", $partner_id);
                $p->execute();
                $pr = $p->get_result()->fetch_assoc();
                $recipient_email = $pr['email'] ?? '';
                $recipient_name = $pr['full_name'] ?? '';
            }
            
            if ($recipient_email) {
                sendMessageNotificationEmail(
                    $recipient_email,
                    $recipient_name,
                    $student_data['email'] ?? '',
                    $student_data['full_name'] ?? '',
                    $subject,
                    $message,
                    $message_id,
                    $partner_type
                );
            }
            
            echo json_encode(['status' => 'success', 'message_id' => $message_id]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to send message']);
        }
        exit;
    }
    
    // Search contacts
    if ($action === 'search_contacts') {
        $query = trim($_GET['q'] ?? '');
        $contacts = [];
        
        // Get lecturers from enrolled courses
        $lec_query = "
            SELECT DISTINCT l.lecturer_id as id, l.full_name as name, 'lecturer' as type, vc.course_name as subtitle
            FROM vle_enrollments ve
            JOIN vle_courses vc ON ve.course_id = vc.course_id
            JOIN lecturers l ON vc.lecturer_id = l.lecturer_id
            WHERE ve.student_id = ?
        ";
        if ($query) {
            $lec_query .= " AND l.full_name LIKE ?";
        }
        $lec_query .= " ORDER BY l.full_name LIMIT 20";
        
        $stmt = $conn->prepare($lec_query);
        if ($query) {
            $search = "%$query%";
            $stmt->bind_param("ss", $student_id, $search);
        } else {
            $stmt->bind_param("s", $student_id);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $contacts[] = $row;
        }
        
        // Get classmates
        $student_profile = [];
        $sp_stmt = $conn->prepare("SELECT year_of_study, program FROM students WHERE student_id = ?");
        $sp_stmt->bind_param('s', $student_id);
        $sp_stmt->execute();
        $sp_res = $sp_stmt->get_result();
        if ($sp_row = $sp_res->fetch_assoc()) {
            $student_profile = $sp_row;
        }
        
        if (!empty($student_profile['year_of_study']) && !empty($student_profile['program'])) {
            $stu_query = "
                SELECT s.student_id as id, s.full_name as name, 'student' as type, 'Classmate' as subtitle
                FROM students s 
                WHERE s.program = ? AND s.year_of_study = ? AND s.student_id != ?
            ";
            if ($query) {
                $stu_query .= " AND s.full_name LIKE ?";
            }
            $stu_query .= " ORDER BY s.full_name LIMIT 30";
            
            $stmt = $conn->prepare($stu_query);
            if ($query) {
                $search = "%$query%";
                $stmt->bind_param("siss", $student_profile['program'], $student_profile['year_of_study'], $student_id, $search);
            } else {
                $stmt->bind_param("sis", $student_profile['program'], $student_profile['year_of_study'], $student_id);
            }
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $contacts[] = $row;
            }
        }
        
        // Get admins
        $admin_query = "SELECT user_id as id, username as name, 'admin' as type, 'Administrator' as subtitle FROM users WHERE role = 'staff'";
        if ($query) {
            $admin_query .= " AND username LIKE ?";
            $stmt = $conn->prepare($admin_query . " ORDER BY username LIMIT 10");
            $search = "%$query%";
            $stmt->bind_param("s", $search);
        } else {
            $stmt = $conn->prepare($admin_query . " ORDER BY username LIMIT 10");
        }
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $contacts[] = $row;
        }
        
        echo json_encode(['status' => 'success', 'contacts' => $contacts]);
        exit;
    }
    
    echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
    exit;
}

// Get unread count for badge
$unread_stmt = $conn->prepare("
    SELECT COUNT(*) as count FROM vle_messages 
    WHERE recipient_type = 'student' AND recipient_id = ? AND is_read = 0
");
$unread_stmt->bind_param("s", $student_id);
$unread_stmt->execute();
$total_unread = $unread_stmt->get_result()->fetch_assoc()['count'] ?? 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#1e3c72">
    <title>Messages - VLE System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
    <style>
        :root {
            --msg-primary: #1e3c72;
            --msg-primary-light: #2a5298;
            --msg-accent: #667eea;
            --msg-success: #10b981;
            --msg-bg: #f0f2f5;
            --msg-card: #ffffff;
            --msg-border: #e4e6eb;
            --msg-text: #1c1e21;
            --msg-text-secondary: #65676b;
            --msg-bubble-sent: linear-gradient(135deg, #1e3c72 0%, #667eea 100%);
            --msg-bubble-received: #e4e6eb;
            --msg-shadow: 0 2px 12px rgba(0,0,0,0.08);
            --msg-radius: 12px;
        }
        
        body {
            background: var(--msg-bg);
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
        }
        
        .messenger-container {
            height: calc(100vh - 140px);
            min-height: 600px;
            max-height: 800px;
            display: flex;
            background: var(--msg-card);
            border-radius: var(--msg-radius);
            box-shadow: var(--msg-shadow);
            overflow: hidden;
        }
        
        /* Sidebar - Conversations List */
        .msg-sidebar {
            width: 340px;
            min-width: 280px;
            border-right: 1px solid var(--msg-border);
            display: flex;
            flex-direction: column;
            background: var(--msg-card);
        }
        
        .msg-sidebar-header {
            padding: 16px 20px;
            border-bottom: 1px solid var(--msg-border);
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            color: white;
        }
        
        .msg-sidebar-header h4 {
            margin: 0;
            font-weight: 700;
            font-size: 1.25rem;
        }
        
        .msg-search-box {
            padding: 12px 16px;
            border-bottom: 1px solid var(--msg-border);
        }
        
        .msg-search-input {
            background: var(--msg-bg);
            border: none;
            border-radius: 20px;
            padding: 10px 16px;
            font-size: 0.9rem;
            width: 100%;
        }
        
        .msg-search-input:focus {
            outline: none;
            box-shadow: 0 0 0 2px rgba(102, 126, 234, 0.3);
        }
        
        .msg-conversations {
            flex: 1;
            overflow-y: auto;
        }
        
        .msg-conversation-item {
            display: flex;
            align-items: center;
            padding: 12px 16px;
            cursor: pointer;
            transition: background 0.2s;
            border-bottom: 1px solid var(--msg-border);
        }
        
        .msg-conversation-item:hover {
            background: var(--msg-bg);
        }
        
        .msg-conversation-item.active {
            background: linear-gradient(135deg, rgba(30, 60, 114, 0.08) 0%, rgba(102, 126, 234, 0.08) 100%);
            border-left: 3px solid var(--msg-primary);
        }
        
        .msg-avatar {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: var(--msg-bubble-sent);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 1.1rem;
            margin-right: 12px;
            flex-shrink: 0;
        }
        
        .msg-avatar.lecturer { background: linear-gradient(135deg, #7c3aed 0%, #a78bfa 100%); }
        .msg-avatar.student { background: linear-gradient(135deg, #059669 0%, #34d399 100%); }
        .msg-avatar.admin { background: linear-gradient(135deg, #dc2626 0%, #f87171 100%); }
        
        .msg-conv-info {
            flex: 1;
            min-width: 0;
        }
        
        .msg-conv-name {
            font-weight: 600;
            font-size: 0.95rem;
            color: var(--msg-text);
            margin-bottom: 2px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .msg-conv-preview {
            font-size: 0.85rem;
            color: var(--msg-text-secondary);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .msg-conv-meta {
            text-align: right;
            flex-shrink: 0;
        }
        
        .msg-conv-time {
            font-size: 0.75rem;
            color: var(--msg-text-secondary);
            margin-bottom: 4px;
        }
        
        .msg-unread-badge {
            background: var(--msg-accent);
            color: white;
            font-size: 0.7rem;
            font-weight: 600;
            padding: 2px 8px;
            border-radius: 10px;
        }
        
        /* Chat Area */
        .msg-chat-area {
            flex: 1;
            display: flex;
            flex-direction: column;
            background: var(--msg-bg);
        }
        
        .msg-chat-header {
            padding: 16px 20px;
            background: var(--msg-card);
            border-bottom: 1px solid var(--msg-border);
            display: flex;
            align-items: center;
            box-shadow: 0 1px 4px rgba(0,0,0,0.04);
        }
        
        .msg-chat-header-info h5 {
            margin: 0;
            font-weight: 600;
            color: var(--msg-text);
        }
        
        .msg-chat-header-info small {
            color: var(--msg-text-secondary);
        }
        
        .msg-messages-container {
            flex: 1;
            overflow-y: auto;
            padding: 20px;
            display: flex;
            flex-direction: column;
        }
        
        .msg-empty-state {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: var(--msg-text-secondary);
            text-align: center;
            padding: 40px;
        }
        
        .msg-empty-state i {
            font-size: 4rem;
            margin-bottom: 16px;
            opacity: 0.3;
        }
        
        .msg-bubble-wrapper {
            display: flex;
            margin-bottom: 12px;
        }
        
        .msg-bubble-wrapper.sent {
            justify-content: flex-end;
        }
        
        .msg-bubble-wrapper.received {
            justify-content: flex-start;
        }
        
        .msg-bubble {
            max-width: 70%;
            padding: 12px 16px;
            border-radius: 18px;
            position: relative;
        }
        
        .msg-bubble.sent {
            background: var(--msg-bubble-sent);
            color: white;
            border-bottom-right-radius: 4px;
        }
        
        .msg-bubble.received {
            background: var(--msg-card);
            color: var(--msg-text);
            border-bottom-left-radius: 4px;
            border: 1px solid var(--msg-border);
        }
        
        .msg-bubble-subject {
            font-weight: 600;
            font-size: 0.85rem;
            margin-bottom: 4px;
            opacity: 0.9;
        }
        
        .msg-bubble-text {
            font-size: 0.95rem;
            line-height: 1.4;
            word-wrap: break-word;
        }
        
        .msg-bubble-time {
            font-size: 0.7rem;
            opacity: 0.7;
            margin-top: 6px;
            text-align: right;
        }
        
        .msg-bubble.received .msg-bubble-time {
            color: var(--msg-text-secondary);
        }
        
        /* Composer */
        .msg-composer {
            padding: 16px 20px;
            background: var(--msg-card);
            border-top: 1px solid var(--msg-border);
        }
        
        .msg-composer-inner {
            display: flex;
            gap: 12px;
            align-items: flex-end;
        }
        
        .msg-input-wrapper {
            flex: 1;
            background: var(--msg-bg);
            border-radius: 24px;
            padding: 8px 16px;
        }
        
        .msg-subject-input {
            border: none;
            background: transparent;
            width: 100%;
            font-size: 0.85rem;
            padding: 4px 0;
            color: var(--msg-text-secondary);
        }
        
        .msg-subject-input:focus {
            outline: none;
        }
        
        .msg-text-input {
            border: none;
            background: transparent;
            width: 100%;
            font-size: 0.95rem;
            padding: 4px 0;
            resize: none;
            max-height: 120px;
        }
        
        .msg-text-input:focus {
            outline: none;
        }
        
        .msg-send-btn {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: var(--msg-bubble-sent);
            border: none;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: transform 0.2s, opacity 0.2s;
            flex-shrink: 0;
        }
        
        .msg-send-btn:hover {
            transform: scale(1.05);
        }
        
        .msg-send-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        /* New Conversation Modal */
        .msg-new-btn {
            background: var(--msg-accent);
            border: none;
            color: white;
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 6px;
            transition: background 0.2s;
        }
        
        .msg-new-btn:hover {
            background: var(--msg-primary);
        }
        
        /* Contact Dropdown */
        .msg-contact-dropdown {
            position: relative;
        }
        
        .msg-contact-search {
            padding: 12px 16px;
            border: 1px solid var(--msg-border);
            border-radius: var(--msg-radius);
            width: 100%;
            font-size: 0.95rem;
        }
        
        .msg-contact-results {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: var(--msg-card);
            border: 1px solid var(--msg-border);
            border-radius: var(--msg-radius);
            box-shadow: var(--msg-shadow);
            max-height: 300px;
            overflow-y: auto;
            z-index: 1000;
            display: none;
        }
        
        .msg-contact-results.show {
            display: block;
        }
        
        .msg-contact-item {
            display: flex;
            align-items: center;
            padding: 10px 16px;
            cursor: pointer;
            transition: background 0.2s;
        }
        
        .msg-contact-item:hover {
            background: var(--msg-bg);
        }
        
        .msg-contact-item .msg-avatar {
            width: 36px;
            height: 36px;
            font-size: 0.9rem;
        }
        
        .msg-contact-item-info {
            flex: 1;
        }
        
        .msg-contact-item-name {
            font-weight: 600;
            font-size: 0.9rem;
        }
        
        .msg-contact-item-subtitle {
            font-size: 0.8rem;
            color: var(--msg-text-secondary);
        }
        
        .msg-type-badge {
            font-size: 0.7rem;
            padding: 2px 8px;
            border-radius: 10px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .msg-type-badge.lecturer { background: rgba(124, 58, 237, 0.1); color: #7c3aed; }
        .msg-type-badge.student { background: rgba(5, 150, 105, 0.1); color: #059669; }
        .msg-type-badge.admin { background: rgba(220, 38, 38, 0.1); color: #dc2626; }
        
        /* Date Separator */
        .msg-date-separator {
            text-align: center;
            margin: 20px 0;
            position: relative;
        }
        
        .msg-date-separator span {
            background: var(--msg-bg);
            color: var(--msg-text-secondary);
            font-size: 0.8rem;
            padding: 4px 12px;
            border-radius: 12px;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .messenger-container {
                flex-direction: column;
                height: calc(100vh - 120px);
            }
            
            .msg-sidebar {
                width: 100%;
                height: auto;
                max-height: 40%;
                border-right: none;
                border-bottom: 1px solid var(--msg-border);
            }
            
            .msg-sidebar.hidden {
                display: none;
            }
            
            .msg-chat-area.active {
                display: flex;
            }
            
            .msg-back-btn {
                display: inline-flex;
            }
            
            .msg-bubble {
                max-width: 85%;
            }
        }
        
        @media (min-width: 769px) {
            .msg-back-btn {
                display: none;
            }
        }
        
        /* Loading spinner */
        .msg-loading {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px;
        }
        
        .msg-spinner {
            width: 32px;
            height: 32px;
            border: 3px solid var(--msg-border);
            border-top-color: var(--msg-accent);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        /* Typing indicator */
        .msg-typing {
            display: flex;
            align-items: center;
            padding: 8px 16px;
            font-size: 0.85rem;
            color: var(--msg-text-secondary);
        }
        
        .msg-typing-dots {
            display: flex;
            gap: 4px;
            margin-left: 8px;
        }
        
        .msg-typing-dots span {
            width: 6px;
            height: 6px;
            background: var(--msg-text-secondary);
            border-radius: 50%;
            animation: typing-bounce 1.4s infinite;
        }
        
        .msg-typing-dots span:nth-child(2) { animation-delay: 0.2s; }
        .msg-typing-dots span:nth-child(3) { animation-delay: 0.4s; }
        
        @keyframes typing-bounce {
            0%, 60%, 100% { transform: translateY(0); }
            30% { transform: translateY(-4px); }
        }
    </style>
</head>
<body>
    <?php 
    $page_title = "Messages";
    $breadcrumbs = [['title' => 'Messages']];
    include 'header_nav.php'; 
    ?>

    <div class="vle-content">
        <div class="messenger-container">
            <!-- Sidebar - Conversations -->
            <div class="msg-sidebar" id="msgSidebar">
                <div class="msg-sidebar-header d-flex justify-content-between align-items-center">
                    <h4><i class="bi bi-chat-dots-fill me-2"></i>Messages</h4>
                    <button class="msg-new-btn" onclick="openNewConversation()">
                        <i class="bi bi-plus-lg"></i>
                        <span class="d-none d-sm-inline">New</span>
                    </button>
                </div>
                <div class="msg-search-box">
                    <input type="text" class="msg-search-input" placeholder="Search conversations..." id="conversationSearch">
                </div>
                <div class="msg-conversations" id="conversationsList">
                    <div class="msg-loading">
                        <div class="msg-spinner"></div>
                    </div>
                </div>
            </div>
            
            <!-- Chat Area -->
            <div class="msg-chat-area" id="chatArea">
                <div class="msg-empty-state" id="emptyState">
                    <i class="bi bi-chat-square-text"></i>
                    <h5>Welcome to Messages</h5>
                    <p>Select a conversation or start a new one to begin messaging</p>
                    <button class="btn btn-primary mt-3" onclick="openNewConversation()">
                        <i class="bi bi-plus-lg me-2"></i>Start New Conversation
                    </button>
                </div>
                
                <div class="msg-chat-header d-none" id="chatHeader">
                    <button class="btn btn-link msg-back-btn p-0 me-3" onclick="showSidebar()">
                        <i class="bi bi-arrow-left fs-5"></i>
                    </button>
                    <div class="msg-avatar" id="chatAvatar"></div>
                    <div class="msg-chat-header-info">
                        <h5 id="chatPartnerName">Select a conversation</h5>
                        <small id="chatPartnerType"></small>
                    </div>
                </div>
                
                <div class="msg-messages-container d-none" id="messagesContainer">
                    <!-- Messages loaded here -->
                </div>
                
                <div class="msg-composer d-none" id="messageComposer">
                    <div class="msg-composer-inner">
                        <div class="msg-input-wrapper">
                            <input type="text" class="msg-subject-input" id="msgSubject" placeholder="Subject (optional)">
                            <textarea class="msg-text-input" id="msgText" placeholder="Type a message..." rows="1"></textarea>
                        </div>
                        <button class="msg-send-btn" id="sendBtn" disabled onclick="sendMessage()">
                            <i class="bi bi-send-fill"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- New Conversation Modal -->
    <div class="modal fade" id="newConversationModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content" style="border-radius: var(--msg-radius); border: none;">
                <div class="modal-header" style="background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%); color: white; border-radius: var(--msg-radius) var(--msg-radius) 0 0;">
                    <h5 class="modal-title"><i class="bi bi-pencil-square me-2"></i>New Conversation</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Select Recipient</label>
                        <div class="msg-contact-dropdown">
                            <input type="text" class="msg-contact-search" id="contactSearchInput" placeholder="Search lecturers, classmates, or admins..." autocomplete="off">
                            <div class="msg-contact-results" id="contactResults"></div>
                        </div>
                    </div>
                    <div id="selectedRecipient" class="d-none mb-3 p-3 rounded" style="background: var(--msg-bg);">
                        <div class="d-flex align-items-center justify-content-between">
                            <div class="d-flex align-items-center">
                                <div class="msg-avatar me-3" id="selectedAvatar"></div>
                                <div>
                                    <div class="fw-semibold" id="selectedName"></div>
                                    <small class="text-muted" id="selectedSubtitle"></small>
                                </div>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="clearRecipient()">
                                <i class="bi bi-x"></i>
                            </button>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Subject <span class="text-muted fw-normal">(optional)</span></label>
                        <input type="text" class="form-control" id="newMsgSubject" placeholder="Enter subject">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Message</label>
                        <textarea class="form-control" id="newMsgText" rows="4" placeholder="Write your message..."></textarea>
                    </div>
                </div>
                <div class="modal-footer border-0">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="sendNewMsgBtn" onclick="sendNewMessage()" disabled>
                        <i class="bi bi-send me-2"></i>Send Message
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const studentId = '<?php echo $student_id; ?>';
        let currentPartner = null;
        let conversationsData = [];
        let selectedRecipient = null;
        let searchTimeout = null;
        
        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            loadConversations();
            setupEventListeners();
        });
        
        function setupEventListeners() {
            // Message input auto-resize
            const msgText = document.getElementById('msgText');
            msgText.addEventListener('input', function() {
                this.style.height = 'auto';
                this.style.height = Math.min(this.scrollHeight, 120) + 'px';
                document.getElementById('sendBtn').disabled = !this.value.trim();
            });
            
            // Enter to send
            msgText.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    if (this.value.trim()) sendMessage();
                }
            });
            
            // Conversation search
            document.getElementById('conversationSearch').addEventListener('input', function() {
                filterConversations(this.value);
            });
            
            // Contact search in modal
            document.getElementById('contactSearchInput').addEventListener('input', function() {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(() => searchContacts(this.value), 300);
            });
            
            document.getElementById('contactSearchInput').addEventListener('focus', function() {
                if (this.value.length >= 0) {
                    searchContacts(this.value);
                }
            });
            
            // Hide contact results when clicking outside
            document.addEventListener('click', function(e) {
                if (!e.target.closest('.msg-contact-dropdown')) {
                    document.getElementById('contactResults').classList.remove('show');
                }
            });
            
            // New message text validation
            document.getElementById('newMsgText').addEventListener('input', function() {
                validateNewMessage();
            });
        }
        
        async function loadConversations() {
            try {
                const response = await fetch('?ajax=1&action=get_conversations');
                const data = await response.json();
                
                if (data.status === 'success') {
                    conversationsData = data.conversations;
                    renderConversations(data.conversations);
                }
            } catch (error) {
                console.error('Error loading conversations:', error);
            }
        }
        
        function renderConversations(conversations) {
            const container = document.getElementById('conversationsList');
            
            if (conversations.length === 0) {
                container.innerHTML = `
                    <div class="text-center p-4 text-muted">
                        <i class="bi bi-chat-square-text fs-1 mb-3 d-block opacity-50"></i>
                        <p class="mb-0">No conversations yet</p>
                        <small>Start a new conversation to begin messaging</small>
                    </div>
                `;
                return;
            }
            
            container.innerHTML = conversations.map(conv => `
                <div class="msg-conversation-item ${currentPartner && currentPartner.type === conv.partner_type && currentPartner.id === conv.partner_id ? 'active' : ''}" 
                     onclick="openConversation('${conv.partner_type}', '${conv.partner_id}', '${escapeHtml(conv.partner_name)}')">
                    <div class="msg-avatar ${conv.partner_type}">
                        ${getInitials(conv.partner_name)}
                    </div>
                    <div class="msg-conv-info">
                        <div class="msg-conv-name">${escapeHtml(conv.partner_name)}</div>
                        <div class="msg-conv-preview">${conv.is_sender ? 'You: ' : ''}${escapeHtml(conv.last_message)}</div>
                    </div>
                    <div class="msg-conv-meta">
                        <div class="msg-conv-time">${formatTime(conv.last_date)}</div>
                        ${conv.unread > 0 ? `<span class="msg-unread-badge">${conv.unread}</span>` : ''}
                    </div>
                </div>
            `).join('');
        }
        
        function filterConversations(query) {
            const filtered = conversationsData.filter(conv => 
                conv.partner_name.toLowerCase().includes(query.toLowerCase())
            );
            renderConversations(filtered);
        }
        
        async function openConversation(partnerType, partnerId, partnerName) {
            currentPartner = { type: partnerType, id: partnerId, name: partnerName };
            
            // Update UI
            document.getElementById('emptyState').classList.add('d-none');
            document.getElementById('chatHeader').classList.remove('d-none');
            document.getElementById('messagesContainer').classList.remove('d-none');
            document.getElementById('messageComposer').classList.remove('d-none');
            
            // Update header
            document.getElementById('chatPartnerName').textContent = partnerName;
            document.getElementById('chatPartnerType').textContent = capitalize(partnerType);
            const avatar = document.getElementById('chatAvatar');
            avatar.className = `msg-avatar ${partnerType}`;
            avatar.textContent = getInitials(partnerName);
            
            // Mark active in list
            document.querySelectorAll('.msg-conversation-item').forEach(el => el.classList.remove('active'));
            document.querySelectorAll('.msg-conversation-item').forEach(el => {
                if (el.onclick.toString().includes(partnerId)) {
                    el.classList.add('active');
                }
            });
            
            // Hide sidebar on mobile
            if (window.innerWidth <= 768) {
                document.getElementById('msgSidebar').classList.add('hidden');
            }
            
            // Load messages
            await loadMessages(partnerType, partnerId);
        }
        
        async function loadMessages(partnerType, partnerId) {
            const container = document.getElementById('messagesContainer');
            container.innerHTML = '<div class="msg-loading"><div class="msg-spinner"></div></div>';
            
            try {
                const response = await fetch(`?ajax=1&action=get_messages&partner_type=${partnerType}&partner_id=${partnerId}`);
                const data = await response.json();
                
                if (data.status === 'success') {
                    renderMessages(data.messages);
                    // Refresh conversation list for unread counts
                    loadConversations();
                }
            } catch (error) {
                console.error('Error loading messages:', error);
                container.innerHTML = '<div class="text-center text-muted p-4">Error loading messages</div>';
            }
        }
        
        function renderMessages(messages) {
            const container = document.getElementById('messagesContainer');
            
            if (messages.length === 0) {
                container.innerHTML = `
                    <div class="text-center text-muted p-4">
                        <i class="bi bi-chat-square fs-1 mb-3 d-block opacity-50"></i>
                        <p>No messages yet. Start the conversation!</p>
                    </div>
                `;
                return;
            }
            
            let html = '';
            let lastDate = null;
            
            messages.forEach(msg => {
                const msgDate = new Date(msg.date).toDateString();
                if (msgDate !== lastDate) {
                    html += `<div class="msg-date-separator"><span>${formatDate(msg.date)}</span></div>`;
                    lastDate = msgDate;
                }
                
                html += `
                    <div class="msg-bubble-wrapper ${msg.is_mine ? 'sent' : 'received'}">
                        <div class="msg-bubble ${msg.is_mine ? 'sent' : 'received'}">
                            ${msg.subject ? `<div class="msg-bubble-subject">${escapeHtml(msg.subject)}</div>` : ''}
                            <div class="msg-bubble-text">${escapeHtml(msg.message).replace(/\n/g, '<br>')}</div>
                            <div class="msg-bubble-time">
                                ${formatTime(msg.date)}
                                ${msg.is_mine ? `<i class="bi bi-check2${msg.is_read ? '-all' : ''} ms-1"></i>` : ''}
                            </div>
                        </div>
                    </div>
                `;
            });
            
            container.innerHTML = html;
            container.scrollTop = container.scrollHeight;
        }
        
        async function sendMessage() {
            if (!currentPartner) return;
            
            const subject = document.getElementById('msgSubject').value.trim();
            const message = document.getElementById('msgText').value.trim();
            
            if (!message) return;
            
            const sendBtn = document.getElementById('sendBtn');
            sendBtn.disabled = true;
            
            try {
                const formData = new FormData();
                formData.append('ajax', '1');
                formData.append('action', 'send_message');
                formData.append('partner_type', currentPartner.type);
                formData.append('partner_id', currentPartner.id);
                formData.append('subject', subject);
                formData.append('message', message);
                
                const response = await fetch('', { method: 'POST', body: formData });
                const data = await response.json();
                
                if (data.status === 'success') {
                    document.getElementById('msgSubject').value = '';
                    document.getElementById('msgText').value = '';
                    document.getElementById('msgText').style.height = 'auto';
                    
                    // Reload messages
                    await loadMessages(currentPartner.type, currentPartner.id);
                } else {
                    alert('Failed to send message: ' + (data.message || 'Unknown error'));
                }
            } catch (error) {
                console.error('Error sending message:', error);
                alert('Failed to send message. Please try again.');
            }
            
            sendBtn.disabled = true;
        }
        
        function openNewConversation() {
            clearRecipient();
            document.getElementById('contactSearchInput').value = '';
            document.getElementById('newMsgSubject').value = '';
            document.getElementById('newMsgText').value = '';
            document.getElementById('contactResults').classList.remove('show');
            
            const modal = new bootstrap.Modal(document.getElementById('newConversationModal'));
            modal.show();
            
            setTimeout(() => {
                document.getElementById('contactSearchInput').focus();
            }, 300);
        }
        
        async function searchContacts(query) {
            const container = document.getElementById('contactResults');
            
            try {
                const response = await fetch(`?ajax=1&action=search_contacts&q=${encodeURIComponent(query)}`);
                const data = await response.json();
                
                if (data.status === 'success') {
                    if (data.contacts.length === 0) {
                        container.innerHTML = '<div class="p-3 text-center text-muted">No contacts found</div>';
                    } else {
                        container.innerHTML = data.contacts.map(contact => `
                            <div class="msg-contact-item" onclick="selectRecipient('${contact.type}', '${contact.id}', '${escapeHtml(contact.name)}', '${escapeHtml(contact.subtitle || '')}')">
                                <div class="msg-avatar ${contact.type}">${getInitials(contact.name)}</div>
                                <div class="msg-contact-item-info">
                                    <div class="msg-contact-item-name">${escapeHtml(contact.name)}</div>
                                    <div class="msg-contact-item-subtitle">${escapeHtml(contact.subtitle || '')}</div>
                                </div>
                                <span class="msg-type-badge ${contact.type}">${contact.type}</span>
                            </div>
                        `).join('');
                    }
                    container.classList.add('show');
                }
            } catch (error) {
                console.error('Error searching contacts:', error);
            }
        }
        
        function selectRecipient(type, id, name, subtitle) {
            selectedRecipient = { type, id, name, subtitle };
            
            document.getElementById('contactSearchInput').classList.add('d-none');
            document.getElementById('selectedRecipient').classList.remove('d-none');
            document.getElementById('selectedName').textContent = name;
            document.getElementById('selectedSubtitle').textContent = subtitle;
            
            const avatar = document.getElementById('selectedAvatar');
            avatar.className = `msg-avatar ${type}`;
            avatar.textContent = getInitials(name);
            
            document.getElementById('contactResults').classList.remove('show');
            
            validateNewMessage();
        }
        
        function clearRecipient() {
            selectedRecipient = null;
            document.getElementById('contactSearchInput').classList.remove('d-none');
            document.getElementById('selectedRecipient').classList.add('d-none');
            document.getElementById('contactSearchInput').value = '';
            validateNewMessage();
        }
        
        function validateNewMessage() {
            const hasRecipient = selectedRecipient !== null;
            const hasMessage = document.getElementById('newMsgText').value.trim().length > 0;
            document.getElementById('sendNewMsgBtn').disabled = !(hasRecipient && hasMessage);
        }
        
        async function sendNewMessage() {
            if (!selectedRecipient) return;
            
            const subject = document.getElementById('newMsgSubject').value.trim();
            const message = document.getElementById('newMsgText').value.trim();
            
            if (!message) return;
            
            const sendBtn = document.getElementById('sendNewMsgBtn');
            sendBtn.disabled = true;
            sendBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Sending...';
            
            try {
                const formData = new FormData();
                formData.append('ajax', '1');
                formData.append('action', 'send_message');
                formData.append('partner_type', selectedRecipient.type);
                formData.append('partner_id', selectedRecipient.id);
                formData.append('subject', subject);
                formData.append('message', message);
                
                const response = await fetch('', { method: 'POST', body: formData });
                const data = await response.json();
                
                if (data.status === 'success') {
                    // Close modal
                    bootstrap.Modal.getInstance(document.getElementById('newConversationModal')).hide();
                    
                    // Open the new conversation
                    await loadConversations();
                    openConversation(selectedRecipient.type, selectedRecipient.id, selectedRecipient.name);
                } else {
                    alert('Failed to send message: ' + (data.message || 'Unknown error'));
                }
            } catch (error) {
                console.error('Error sending message:', error);
                alert('Failed to send message. Please try again.');
            }
            
            sendBtn.disabled = false;
            sendBtn.innerHTML = '<i class="bi bi-send me-2"></i>Send Message';
        }
        
        function showSidebar() {
            document.getElementById('msgSidebar').classList.remove('hidden');
        }
        
        // Utility functions
        function getInitials(name) {
            if (!name) return '?';
            const parts = name.trim().split(' ');
            if (parts.length >= 2) {
                return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
            }
            return name[0].toUpperCase();
        }
        
        function formatTime(dateStr) {
            const date = new Date(dateStr);
            const now = new Date();
            const diff = now - date;
            
            // Today - show time
            if (date.toDateString() === now.toDateString()) {
                return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
            }
            
            // Yesterday
            const yesterday = new Date(now);
            yesterday.setDate(yesterday.getDate() - 1);
            if (date.toDateString() === yesterday.toDateString()) {
                return 'Yesterday';
            }
            
            // This week - show day name
            if (diff < 7 * 24 * 60 * 60 * 1000) {
                return date.toLocaleDateString([], { weekday: 'short' });
            }
            
            // Older - show date
            return date.toLocaleDateString([], { month: 'short', day: 'numeric' });
        }
        
        function formatDate(dateStr) {
            const date = new Date(dateStr);
            const now = new Date();
            
            if (date.toDateString() === now.toDateString()) {
                return 'Today';
            }
            
            const yesterday = new Date(now);
            yesterday.setDate(yesterday.getDate() - 1);
            if (date.toDateString() === yesterday.toDateString()) {
                return 'Yesterday';
            }
            
            return date.toLocaleDateString([], { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' });
        }
        
        function capitalize(str) {
            return str.charAt(0).toUpperCase() + str.slice(1);
        }
        
        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        // Poll for new messages every 30 seconds
        setInterval(() => {
            loadConversations();
            if (currentPartner) {
                loadMessages(currentPartner.type, currentPartner.id);
            }
        }, 30000);
    </script>
</body>
</html>
