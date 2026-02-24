<?php
// messages.php - Admin messaging system
require_once '../includes/auth.php';
require_once '../includes/email.php';
requireLogin();
requireRole(['staff', 'admin']);

$conn = getDbConnection();
$user = getCurrentUser();
$admin_id = $_SESSION['vle_user_id'];

// Handle sending messages
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message'])) {
    $recipient_type = $_POST['recipient_type'] ?? '';
    $recipient_id = $_POST['recipient_id'] ?? '';
    $subject = trim($_POST['subject'] ?? '');
    $message = trim($_POST['message'] ?? '');

    if (!empty($recipient_id) && !empty($subject) && !empty($message)) {
        $stmt = $conn->prepare("INSERT INTO vle_messages (sender_type, sender_id, recipient_type, recipient_id, subject, message) VALUES ('admin', ?, ?, ?, ?, ?)");
        $stmt->bind_param("issss", $admin_id, $recipient_type, $recipient_id, $subject, $message);
        
        if ($stmt->execute()) {
            $message_id = $conn->insert_id;
            $success = "Message sent successfully!";
            
            // Get sender details (users table has username, not full_name)
            $sender_query = $conn->prepare("SELECT username as full_name, email FROM users WHERE user_id = ?");
            $sender_query->bind_param("i", $admin_id);
            $sender_query->execute();
            $sender_data = $sender_query->get_result()->fetch_assoc();
            
            // Get recipient details based on type
            if ($recipient_type === 'lecturer') {
                $recipient_query = $conn->prepare("SELECT full_name, email FROM lecturers WHERE lecturer_id = ?");
                $recipient_query->bind_param("i", $recipient_id);
            } elseif ($recipient_type === 'student') {
                $recipient_query = $conn->prepare("SELECT full_name, email FROM students WHERE student_id = ?");
                $recipient_query->bind_param("s", $recipient_id);
            } elseif ($recipient_type === 'finance') {
                $recipient_query = $conn->prepare("SELECT full_name, email FROM finance_users WHERE finance_id = ?");
                $recipient_query->bind_param("i", $recipient_id);
            } else {
                $recipient_query = $conn->prepare("SELECT username as full_name, email FROM users WHERE user_id = ?");
                $recipient_query->bind_param("i", $recipient_id);
            }
            $recipient_query->execute();
            $recipient_data = $recipient_query->get_result()->fetch_assoc();
            
            // Send email notification
            if ($recipient_data && $recipient_data['email'] && $sender_data && $sender_data['email']) {
                sendMessageNotificationEmail(
                    $recipient_data['email'],
                    $recipient_data['full_name'],
                    $sender_data['email'],
                    $sender_data['full_name'],
                    $subject,
                    $message,
                    $message_id,
                    $recipient_type
                );
            }
        } else {
            $error = "Failed to send message.";
        }
    } else {
        $error = "Please fill in all required fields.";
    }
}

// Get inbox messages (messages sent TO admin)
$inbox = [];
$stmt = $conn->prepare("
    SELECT m.*, 
           CASE 
               WHEN m.sender_type = 'lecturer' THEN l.full_name
               WHEN m.sender_type = 'student' THEN s.full_name
               WHEN m.sender_type = 'admin' THEN u.username
               WHEN m.sender_type = 'finance' THEN f.full_name
           END as sender_name,
           m.sender_type
    FROM vle_messages m
    LEFT JOIN lecturers l ON m.sender_type = 'lecturer' AND m.sender_id = l.lecturer_id
    LEFT JOIN students s ON m.sender_type = 'student' AND m.sender_id = s.student_id
    LEFT JOIN users u ON m.sender_type = 'admin' AND m.sender_id = u.user_id
    LEFT JOIN finance_users f ON m.sender_type = 'finance' AND m.sender_id = f.finance_id
    WHERE m.recipient_type = 'admin' AND m.recipient_id = ?
    ORDER BY m.sent_date DESC
");
$stmt->bind_param("i", $admin_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $inbox[] = $row;
}

// Get sent messages
$sent = [];
$stmt = $conn->prepare("
    SELECT m.*,
           CASE 
               WHEN m.recipient_type = 'lecturer' THEN l.full_name
               WHEN m.recipient_type = 'student' THEN s.full_name
               WHEN m.recipient_type = 'admin' THEN u.username
               WHEN m.recipient_type = 'finance' THEN f.full_name
           END as recipient_name,
           m.recipient_type
    FROM vle_messages m
    LEFT JOIN lecturers l ON m.recipient_type = 'lecturer' AND m.recipient_id = l.lecturer_id
    LEFT JOIN students s ON m.recipient_type = 'student' AND m.recipient_id = s.student_id
    LEFT JOIN users u ON m.recipient_type = 'admin' AND m.recipient_id = u.user_id
    LEFT JOIN finance_users f ON m.recipient_type = 'finance' AND m.recipient_id = f.finance_id
    WHERE m.sender_type = 'admin' AND m.sender_id = ?
    ORDER BY m.sent_date DESC
");
$stmt->bind_param("i", $admin_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $sent[] = $row;
}

// Get all lecturers for contact list (exclude finance role)
$lecturers = [];
$result = $conn->query("SELECT lecturer_id, full_name, email, department FROM lecturers WHERE role IS NULL OR role != 'finance' ORDER BY full_name");
while ($row = $result->fetch_assoc()) {
    $lecturers[] = $row;
}

// Get all finance users for contact list (from dedicated finance_users table)
$finance_users = [];
// Check if finance_users table exists
$table_check = $conn->query("SHOW TABLES LIKE 'finance_users'");
if ($table_check && $table_check->num_rows > 0) {
    $result = $conn->query("SELECT finance_id, full_name, email FROM finance_users WHERE is_active = 1 ORDER BY full_name");
    while ($row = $result->fetch_assoc()) {
        $finance_users[] = $row;
    }
} else {
    // Fallback to lecturers table if finance_users table doesn't exist yet
    $result = $conn->query("SELECT lecturer_id as finance_id, full_name, email FROM lecturers WHERE role = 'finance' ORDER BY full_name");
    while ($row = $result->fetch_assoc()) {
        $finance_users[] = $row;
    }
}

// Get all students for contact list
$students = [];
$result = $conn->query("SELECT student_id, full_name, email, program FROM students ORDER BY full_name");
while ($row = $result->fetch_assoc()) {
    $students[] = $row;
}

// Get all other staff/admins for contact list
$admins = [];
$stmt = $conn->prepare("SELECT user_id, username as full_name, email FROM users WHERE role = 'staff' AND user_id != ? ORDER BY username");
$stmt->bind_param("i", $admin_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $admins[] = $row;
}

// Mark messages as read if viewing conversation
if (isset($_GET['mark_read']) && !empty($_GET['mark_read'])) {
    $message_id = (int)$_GET['mark_read'];
    $stmt = $conn->prepare("UPDATE vle_messages SET is_read = 1, read_date = NOW() WHERE message_id = ? AND recipient_type = 'admin' AND recipient_id = ?");
    $stmt->bind_param("ii", $message_id, $admin_id);
    $stmt->execute();
}

// Handle AJAX requests
if ((isset($_GET['action']) && $_GET['action'] === 'fetch_thread') || (isset($_POST['action']) && $_POST['action'] === 'send_ajax')) {
    header('Content-Type: application/json');
    $action = $_GET['action'] ?? $_POST['action'] ?? '';
    $aj_conn = getDbConnection();

    if ($action === 'fetch_thread') {
        $partner_type = $_GET['partner_type'] ?? '';
        $partner_id = $_GET['partner_id'] ?? '';
        $adminId = $_SESSION['vle_user_id'];

        $stmt = $aj_conn->prepare("SELECT m.*, 
            CASE WHEN m.sender_type = 'lecturer' THEN l.full_name 
                 WHEN m.sender_type = 'student' THEN s.full_name 
                 WHEN m.sender_type = 'admin' THEN u.username 
                 WHEN m.sender_type = 'finance' THEN f.full_name
            END as sender_name,
            CASE WHEN m.recipient_type = 'lecturer' THEN rl.full_name 
                 WHEN m.recipient_type = 'student' THEN rs.full_name 
                 WHEN m.recipient_type = 'admin' THEN ru.username 
                 WHEN m.recipient_type = 'finance' THEN rf.full_name
            END as recipient_name
            FROM vle_messages m
            LEFT JOIN lecturers l ON m.sender_type = 'lecturer' AND m.sender_id = l.lecturer_id
            LEFT JOIN students s ON m.sender_type = 'student' AND m.sender_id = s.student_id
            LEFT JOIN users u ON m.sender_type = 'admin' AND m.sender_id = u.user_id
            LEFT JOIN finance_users f ON m.sender_type = 'finance' AND m.sender_id = f.finance_id
            LEFT JOIN lecturers rl ON m.recipient_type = 'lecturer' AND m.recipient_id = rl.lecturer_id
            LEFT JOIN students rs ON m.recipient_type = 'student' AND m.recipient_id = rs.student_id
            LEFT JOIN users ru ON m.recipient_type = 'admin' AND m.recipient_id = ru.user_id
            LEFT JOIN finance_users rf ON m.recipient_type = 'finance' AND m.recipient_id = rf.finance_id
            WHERE (m.sender_type = ? AND m.sender_id = ? AND m.recipient_type = 'admin' AND m.recipient_id = ?) 
               OR (m.recipient_type = ? AND m.recipient_id = ? AND m.sender_type = 'admin' AND m.sender_id = ?)
            ORDER BY m.sent_date ASC");
        $stmt->bind_param('ssissi', $partner_type, $partner_id, $adminId, $partner_type, $partner_id, $adminId);
        $stmt->execute();
        $res = $stmt->get_result();
        $messages = $res->fetch_all(MYSQLI_ASSOC);
        
        // Mark received messages as read
        $update = $aj_conn->prepare("UPDATE vle_messages SET is_read = 1, read_date = NOW() 
                                     WHERE sender_type = ? AND sender_id = ? AND recipient_type = 'admin' AND recipient_id = ? AND is_read = 0");
        $update->bind_param('ssi', $partner_type, $partner_id, $adminId);
        $update->execute();
        
        echo json_encode(['status' => 'ok', 'messages' => $messages]);
        $aj_conn->close();
        exit;
    }

    if ($action === 'send_ajax') {
        $partner_type = $_POST['partner_type'] ?? '';
        $partner_id = $_POST['partner_id'] ?? '';
        $subject = trim($_POST['subject'] ?? '');
        $message = trim($_POST['message'] ?? '');
        $adminId = $_SESSION['vle_user_id'];

        if ($partner_type && $partner_id && $message !== '') {
            $ins = $aj_conn->prepare("INSERT INTO vle_messages (sender_type, sender_id, recipient_type, recipient_id, subject, message) VALUES ('admin', ?, ?, ?, ?, ?)");
            $ins->bind_param('issss', $adminId, $partner_type, $partner_id, $subject, $message);
            if ($ins->execute()) {
                $message_id = $aj_conn->insert_id;
                echo json_encode(['status' => 'ok', 'message_id' => $message_id]);
            } else {
                echo json_encode(['status' => 'error', 'error' => 'Failed to insert message']);
            }
        } else {
            echo json_encode(['status' => 'error', 'error' => 'Missing parameters']);
        }
        $aj_conn->close();
        exit;
    }
}

// Count unread messages
$unread_count = 0;
foreach ($inbox as $msg) {
    if (!$msg['is_read']) $unread_count++;
}

// Note: Don't close $conn here - header_nav.php needs it for getCurrentUser()
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#1e3c72">
    <title>Messages - Admin Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
    <style>
        body { background: var(--vle-bg); }
        .messenger { height: calc(100vh - 200px); min-height: 500px; }
        .contacts { 
            border-right: 1px solid var(--vle-border); 
            height: 100%; 
            overflow-y: auto; 
            background: var(--vle-card-bg);
            border-radius: var(--vle-radius-lg) 0 0 var(--vle-radius-lg);
        }
        .conversation { 
            height: 100%; 
            display: flex; 
            flex-direction: column;
            background: var(--vle-card-bg);
            border-radius: 0 var(--vle-radius-lg) var(--vle-radius-lg) 0;
        }
        .messages { flex: 1; overflow-y: auto; padding: 1.5rem; background: var(--vle-bg-light); }
        .message-row { margin-bottom: 12px; }
        .message-row .bubble { 
            padding: 12px 16px; 
            border-radius: 16px; 
            display: inline-block; 
            max-width: 75%;
            box-shadow: var(--vle-shadow-xs);
        }
        .bubble.sent { 
            background: var(--vle-gradient-primary); 
            color: #fff; 
            border-bottom-right-radius: 4px;
        }
        .bubble.received { 
            background: var(--vle-card-bg); 
            color: var(--vle-text);
            border: 1px solid var(--vle-border);
            border-bottom-left-radius: 4px;
        }
        .composer { 
            padding: 16px; 
            border-top: 1px solid var(--vle-border); 
            background: var(--vle-card-bg);
        }
        .contact-item { 
            border: none !important;
            border-bottom: 1px solid var(--vle-border-light) !important;
            padding: 14px 16px !important;
            transition: var(--vle-transition);
        }
        .contact-item:hover { 
            background: var(--vle-bg) !important;
        }
        .contact-item.active { 
            background: linear-gradient(135deg, rgba(30, 60, 114, 0.1) 0%, rgba(102, 126, 234, 0.1) 100%) !important;
            border-left: 3px solid var(--vle-primary) !important;
        }
        .contact-item h6 { 
            color: var(--vle-text); 
            font-weight: 600; 
        }
        .contacts-header {
            background: var(--vle-gradient-primary);
            color: white;
            padding: 16px;
            font-weight: 600;
        }
        .card-header-msg {
            background: var(--vle-gradient-primary);
            color: white;
            padding: 16px 20px;
        }
        .card-header-msg h5 {
            color: white;
            margin: 0;
            font-weight: 600;
        }
        .card-header-msg small {
            color: rgba(255, 255, 255, 0.8);
        }
        .message-card {
            border: none;
            box-shadow: var(--vle-shadow);
            border-radius: var(--vle-radius-lg);
            overflow: hidden;
        }
        .btn-send {
            background: var(--vle-gradient-primary);
            border: none;
            color: white;
            padding: 10px 20px;
            border-radius: var(--vle-radius-sm);
            font-weight: 600;
        }
        .btn-send:hover {
            opacity: 0.9;
            color: white;
        }
        .stat-icon {
            font-size: 2.5rem;
            opacity: 0.3;
            position: absolute;
            right: 15px;
            top: 15px;
        }
        .contact-section-header {
            background: #f8f9fa;
            padding: 8px 16px;
            font-weight: 600;
            font-size: 0.85rem;
            color: #666;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .unread-badge {
            background: #dc3545;
            color: white;
            font-size: 0.7rem;
            padding: 2px 6px;
            border-radius: 10px;
        }
        .text-purple { color: #6f42c1; }
        .recipient-dropdown {
            border: 2px solid #e9ecef;
            border-radius: 8px;
            padding: 10px 12px;
            font-size: 0.95rem;
            transition: all 0.2s;
        }
        .recipient-dropdown:focus {
            border-color: var(--vle-primary);
            box-shadow: 0 0 0 3px rgba(30, 60, 114, 0.1);
        }
        .recipient-dropdown option {
            padding: 8px;
        }
        #selectedRecipientDisplay {
            background: linear-gradient(135deg, rgba(30, 60, 114, 0.1) 0%, rgba(102, 126, 234, 0.1) 100%);
            border: 1px solid rgba(30, 60, 114, 0.2);
            border-radius: 8px;
            padding: 12px 16px;
            font-size: 0.9rem;
        }
        @media (max-width: 768px) {
            .messenger { height: auto; }
            .contacts { border-right: none; border-bottom: 1px solid var(--vle-border); max-height: 400px; overflow-y: auto; }
            .conversation { border-radius: 0 0 var(--vle-radius-lg) var(--vle-radius-lg); }
            .contacts { border-radius: var(--vle-radius-lg) var(--vle-radius-lg) 0 0; }
        }
    </style>
</head>
<body>
    <?php 
    $currentPage = 'messages';
    $pageTitle = 'Messages';
    $breadcrumbs = [['title' => 'Messages']];
    include 'header_nav.php'; 
    ?>

    <div class="vle-content">
        <div class="vle-page-header mb-4">
            <h1 class="h3 mb-1"><i class="bi bi-chat-dots-fill me-2"></i>Messages</h1>
            <p class="text-muted mb-0">Communicate with students, lecturers and other administrators</p>
        </div>

        <?php if (isset($success)): ?>
            <div class="alert vle-alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle-fill"></i> <?php echo $success; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (isset($error)): ?>
            <div class="alert vle-alert-error alert-dismissible fade show">
                <i class="bi bi-exclamation-triangle-fill"></i> <?php echo $error; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Quick Stats -->
        <div class="row mb-4">
            <div class="col-md-4">
                <div class="card vle-card border-info position-relative">
                    <div class="card-body">
                        <i class="bi bi-envelope stat-icon text-info"></i>
                        <h6 class="text-muted text-uppercase">Inbox</h6>
                        <h3 class="mb-0 text-info"><?php echo count($inbox); ?></h3>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card vle-card border-warning position-relative">
                    <div class="card-body">
                        <i class="bi bi-envelope-exclamation stat-icon text-warning"></i>
                        <h6 class="text-muted text-uppercase">Unread</h6>
                        <h3 class="mb-0 text-warning"><?php echo $unread_count; ?></h3>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card vle-card border-success position-relative">
                    <div class="card-body">
                        <i class="bi bi-send stat-icon text-success"></i>
                        <h6 class="text-muted text-uppercase">Sent</h6>
                        <h3 class="mb-0 text-success"><?php echo count($sent); ?></h3>
                    </div>
                </div>
            </div>
        </div>

        <div class="row messenger">
            <div class="col-md-4 p-0">
                <div class="contacts">
                    <div class="contacts-header">
                        <i class="bi bi-people-fill me-2"></i>Select Recipient
                        <?php if ($unread_count > 0): ?>
                            <span class="unread-badge ms-2"><?php echo $unread_count; ?> new</span>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Dropdown Selection Section -->
                    <div class="p-3 border-bottom">
                        <!-- Students Dropdown -->
                        <div class="mb-3">
                            <label class="form-label fw-bold text-primary mb-2">
                                <i class="bi bi-mortarboard me-1"></i>Students (<?php echo count($students); ?>)
                            </label>
                            <select class="form-select recipient-dropdown" id="studentDropdown" data-type="student">
                                <option value="">-- Select a Student --</option>
                                <?php foreach ($students as $stud): ?>
                                    <option value="<?php echo htmlspecialchars($stud['student_id']); ?>" data-name="<?php echo htmlspecialchars($stud['full_name']); ?>">
                                        <?php echo htmlspecialchars($stud['full_name']); ?> - <?php echo htmlspecialchars($stud['program'] ?? 'Student'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <!-- Lecturers Dropdown -->
                        <div class="mb-3">
                            <label class="form-label fw-bold text-purple mb-2">
                                <i class="bi bi-person-badge me-1"></i>Lecturers (<?php echo count($lecturers); ?>)
                            </label>
                            <select class="form-select recipient-dropdown" id="lecturerDropdown" data-type="lecturer">
                                <option value="">-- Select a Lecturer --</option>
                                <?php foreach ($lecturers as $lect): ?>
                                    <option value="<?php echo $lect['lecturer_id']; ?>" data-name="<?php echo htmlspecialchars($lect['full_name']); ?>">
                                        <?php echo htmlspecialchars($lect['full_name']); ?> - <?php echo htmlspecialchars($lect['department'] ?? 'Lecturer'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <?php if (!empty($finance_users)): ?>
                        <!-- Finance Dropdown -->
                        <div class="mb-3">
                            <label class="form-label fw-bold text-success mb-2">
                                <i class="bi bi-cash-coin me-1"></i>Finance (<?php echo count($finance_users); ?>)
                            </label>
                            <select class="form-select recipient-dropdown" id="financeDropdown" data-type="finance">
                                <option value="">-- Select Finance Officer --</option>
                                <?php foreach ($finance_users as $fin): ?>
                                    <option value="<?php echo $fin['finance_id']; ?>" data-name="<?php echo htmlspecialchars($fin['full_name']); ?>">
                                        <?php echo htmlspecialchars($fin['full_name']); ?> - Finance Officer
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($admins)): ?>
                        <!-- Administrators Dropdown -->
                        <div class="mb-3">
                            <label class="form-label fw-bold text-danger mb-2">
                                <i class="bi bi-person-gear me-1"></i>Administrators (<?php echo count($admins); ?>)
                            </label>
                            <select class="form-select recipient-dropdown" id="adminDropdown" data-type="admin">
                                <option value="">-- Select Administrator --</option>
                                <?php foreach ($admins as $adm): ?>
                                    <option value="<?php echo $adm['user_id']; ?>" data-name="<?php echo htmlspecialchars($adm['full_name']); ?>">
                                        <?php echo htmlspecialchars($adm['full_name']); ?> - Administrator
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Selected Recipient Display -->
                        <div id="selectedRecipientDisplay" class="alert alert-info d-none">
                            <strong>Selected:</strong> <span id="selectedRecipientName"></span>
                            <button type="button" class="btn-close float-end" id="clearRecipient" aria-label="Clear"></button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-8 p-0">
                <div class="conversation">
                    <div class="card message-card h-100">
                        <div class="card-header-msg d-flex justify-content-between align-items-center">
                            <div>
                                <h5 id="convTitle">Select a contact to start messaging</h5>
                                <small id="convSubtitle"></small>
                            </div>
                            <div>
                                <button class="btn btn-sm btn-outline-light" id="btnCompose">
                                    <i class="bi bi-pencil-square me-1"></i>Compose
                                </button>
                            </div>
                        </div>
                        <div class="messages" id="messagesArea">
                            <div class="text-center text-muted py-5">
                                <i class="bi bi-chat-dots" style="font-size: 3rem;"></i>
                                <p class="mt-3">Select a contact from the left to view conversation</p>
                            </div>
                        </div>
                        <div class="composer bg-white" id="composerArea" style="display:none;">
                            <form id="sendForm">
                                <div class="row g-2 mb-2">
                                    <div class="col-4">
                                        <select id="compose_recipient_type" class="form-select">
                                            <option value="">Send To...</option>
                                            <option value="student">Student</option>
                                            <option value="lecturer">Lecturer</option>
                                            <option value="finance">Finance</option>
                                            <option value="admin">Staff/Admin</option>
                                        </select>
                                    </div>
                                    <div class="col-8">
                                        <select id="compose_recipient_select" class="form-select">
                                            <option value="">Select recipient...</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="mb-2">
                                    <input type="text" id="msgSubject" class="form-control" placeholder="Subject (optional)">
                                </div>
                                <div class="input-group">
                                    <input type="text" id="msgInput" class="form-control" placeholder="Type a message...">
                                    <button class="btn btn-primary" id="sendBtn" type="button"><i class="bi bi-send"></i> Send</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const adminId = <?php echo $admin_id; ?>;
        let currentPartner = null;
        
        // Server-rendered contact lists for composer
        const studentsList = <?php echo json_encode($students); ?>;
        const lecturersList = <?php echo json_encode($lecturers); ?>;
        const financeList = <?php echo json_encode($finance_users); ?>;
        const adminsList = <?php echo json_encode($admins); ?>;

        // Populate compose recipient select based on type
        const composeType = document.getElementById('compose_recipient_type');
        const composeSelect = document.getElementById('compose_recipient_select');
        
        function fillComposeOptions(type) {
            composeSelect.innerHTML = '<option value="">Select recipient...</option>';
            if (type === 'student') {
                studentsList.forEach(s => {
                    const opt = document.createElement('option');
                    opt.value = s.student_id;
                    opt.text = s.full_name + (s.program ? ' (' + s.program + ')' : '');
                    composeSelect.appendChild(opt);
                });
            } else if (type === 'lecturer') {
                lecturersList.forEach(l => {
                    const opt = document.createElement('option');
                    opt.value = l.lecturer_id;
                    opt.text = l.full_name + (l.department ? ' (' + l.department + ')' : '');
                    composeSelect.appendChild(opt);
                });
            } else if (type === 'finance') {
                financeList.forEach(f => {
                    const opt = document.createElement('option');
                    opt.value = f.finance_id;
                    opt.text = f.full_name;
                    composeSelect.appendChild(opt);
                });
            } else if (type === 'admin') {
                adminsList.forEach(a => {
                    const opt = document.createElement('option');
                    opt.value = a.user_id;
                    opt.text = a.full_name;
                    composeSelect.appendChild(opt);
                });
            }
        }

        composeType.addEventListener('change', function(){
            fillComposeOptions(this.value);
        });

        function renderMessages(messages) {
            const container = document.getElementById('messagesArea');
            container.innerHTML = '';
            if (!messages || messages.length === 0) {
                container.innerHTML = '<div class="text-center text-muted py-4"><i class="bi bi-chat-text" style="font-size:2rem;"></i><p class="mt-2">No messages in this conversation yet.</p></div>';
                return;
            }
            messages.forEach(msg => {
                const isSender = msg.sender_type === 'admin' && msg.sender_id == adminId;
                const row = document.createElement('div');
                row.className = 'message-row d-flex ' + (isSender ? 'justify-content-end' : 'justify-content-start');
                const bubble = document.createElement('div');
                bubble.className = 'bubble ' + (isSender ? 'sent' : 'received');
                bubble.innerHTML = `<div><small class="text-muted">${isSender ? 'You' : (msg.sender_name || msg.sender_type + ' ' + msg.sender_id)}</small></div>
                                    ${msg.subject ? '<div class="mt-1"><strong>' + msg.subject + '</strong></div>' : ''}
                                    <div class="mt-1">${msg.message.replace(/\n/g,'<br>')}</div>
                                    <div class="mt-1"><small class="text-muted">${new Date(msg.sent_date).toLocaleString()}</small></div>`;
                row.appendChild(bubble);
                container.appendChild(row);
            });
            container.scrollTop = container.scrollHeight;
        }

        async function loadThread(partnerType, partnerId, partnerName) {
            currentPartner = { type: partnerType, id: partnerId };
            
            // Update header if name provided
            if (partnerName) {
                document.getElementById('convTitle').innerText = partnerName;
                document.getElementById('convSubtitle').innerText = partnerType.charAt(0).toUpperCase() + partnerType.slice(1);
            }
            document.getElementById('composerArea').style.display = 'block';

            try {
                const resp = await fetch('?action=fetch_thread&partner_type=' + encodeURIComponent(partnerType) + '&partner_id=' + encodeURIComponent(partnerId));
                const data = await resp.json();
                if (data.status === 'ok') {
                    renderMessages(data.messages);
                } else {
                    renderMessages([]);
                }
            } catch (e) {
                console.error(e);
                renderMessages([]);
            }
        }

        // When composer select chosen, set currentPartner accordingly
        composeSelect.addEventListener('change', function(){
            let type = composeType.value;
            const id = this.value;
            if (type && id) {
                // Finance users now have their own table, use 'finance' type directly
                currentPartner = { type: type, id: id };
                const selected = this.options[this.selectedIndex].text;
                document.getElementById('convTitle').innerText = selected;
                document.getElementById('composerArea').style.display = 'block';
            }
        });

        document.getElementById('sendBtn').addEventListener('click', async function(){
            const subject = document.getElementById('msgSubject').value.trim();
            const message = document.getElementById('msgInput').value.trim();
            let partnerType = null;
            let partnerId = null;

            if (currentPartner) {
                partnerType = currentPartner.type;
                partnerId = currentPartner.id;
            } else {
                partnerType = document.getElementById('compose_recipient_type').value;
                // Finance users now have their own type in the database
                partnerId = document.getElementById('compose_recipient_select').value;
            }

            if (!partnerType || !partnerId) { alert('Please select a recipient.'); return; }
            if (!message) { alert('Please enter a message.'); return; }

            const form = new FormData();
            form.append('action','send_ajax');
            form.append('partner_type', partnerType);
            form.append('partner_id', partnerId);
            form.append('subject', subject);
            form.append('message', message);

            try {
                const resp = await fetch('', { method: 'POST', body: form });
                const data = await resp.json();
                if (data.status === 'ok') {
                    document.getElementById('msgInput').value = '';
                    document.getElementById('msgSubject').value = '';
                    setTimeout(()=> loadThread(partnerType, partnerId, document.querySelector('.contact-item.active')), 300);
                } else {
                    alert('Failed to send message: ' + (data.error || 'unknown error'));
                }
            } catch (e) {
                console.error(e);
                alert('Error sending message');
            }
        });

        // Enter key to send
        document.getElementById('msgInput').addEventListener('keypress', function(e){
            if (e.key === 'Enter') {
                e.preventDefault();
                document.getElementById('sendBtn').click();
            }
        });

        document.getElementById('btnCompose').addEventListener('click', function(){
            document.getElementById('composerArea').style.display = 'block';
        });

        // Dropdown Selection Functionality
        const recipientDropdowns = document.querySelectorAll('.recipient-dropdown');
        const selectedRecipientDisplay = document.getElementById('selectedRecipientDisplay');
        const selectedRecipientName = document.getElementById('selectedRecipientName');
        const clearRecipientBtn = document.getElementById('clearRecipient');
        
        recipientDropdowns.forEach(dropdown => {
            dropdown.addEventListener('change', function() {
                const selectedValue = this.value;
                const selectedOption = this.options[this.selectedIndex];
                const recipientType = this.getAttribute('data-type');
                
                if (selectedValue) {
                    // Reset other dropdowns
                    recipientDropdowns.forEach(d => {
                        if (d !== this) d.selectedIndex = 0;
                    });
                    
                    // Set current partner for messaging
                    currentPartner = { type: recipientType, id: selectedValue };
                    
                    // Update UI
                    const recipientName = selectedOption.getAttribute('data-name') || selectedOption.text;
                    document.getElementById('convTitle').innerText = recipientName;
                    document.getElementById('convSubtitle').innerText = recipientType.charAt(0).toUpperCase() + recipientType.slice(1);
                    document.getElementById('composerArea').style.display = 'block';
                    
                    // Show selected recipient indicator
                    selectedRecipientName.textContent = recipientName + ' (' + recipientType + ')';
                    selectedRecipientDisplay.classList.remove('d-none');
                    
                    // Load conversation thread
                    loadThread(recipientType, selectedValue, null);
                }
            });
        });
        
        // Clear selected recipient
        if (clearRecipientBtn) {
            clearRecipientBtn.addEventListener('click', function() {
                recipientDropdowns.forEach(d => d.selectedIndex = 0);
                selectedRecipientDisplay.classList.add('d-none');
                currentPartner = null;
                document.getElementById('convTitle').innerText = 'Select a contact to start messaging';
                document.getElementById('convSubtitle').innerText = '';
                document.getElementById('messagesArea').innerHTML = '<div class="text-center text-muted py-5"><i class="bi bi-chat-dots" style="font-size: 3rem;"></i><p class="mt-3">Select a contact from the dropdowns to view conversation</p></div>';
                document.getElementById('composerArea').style.display = 'none';
            });
        }
    </script>
</body>
</html>
