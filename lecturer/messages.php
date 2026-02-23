<?php
// messages.php - Lecturer messaging system
require_once '../includes/auth.php';
require_once '../includes/email.php';
requireLogin();
requireRole(['lecturer']);

$conn = getDbConnection();
$lecturer_id = $_SESSION['vle_related_id'];
$user = getCurrentUser();

// Handle sending messages
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message'])) {
    $recipient_type = $_POST['recipient_type'] ?? '';
    $recipient_id = $_POST['recipient_id'] ?? '';
    $subject = trim($_POST['subject'] ?? '');
    $message = trim($_POST['message'] ?? '');

    if (!empty($recipient_id) && !empty($subject) && !empty($message)) {
        $stmt = $conn->prepare("INSERT INTO vle_messages (sender_type, sender_id, recipient_type, recipient_id, subject, message) VALUES ('lecturer', ?, ?, ?, ?, ?)");
        $stmt->bind_param("issss", $lecturer_id, $recipient_type, $recipient_id, $subject, $message);
        
        if ($stmt->execute()) {
            $message_id = $conn->insert_id;
            $success = "Message sent successfully!";
            
            // Get sender details
            $sender_query = $conn->prepare("SELECT full_name, email FROM lecturers WHERE lecturer_id = ?");
            $sender_query->bind_param("i", $lecturer_id);
            $sender_query->execute();
            $sender_data = $sender_query->get_result()->fetch_assoc();
            
            // Get recipient details
            if ($recipient_type === 'lecturer') {
                $recipient_query = $conn->prepare("SELECT full_name, email FROM lecturers WHERE lecturer_id = ?");
                $recipient_query->bind_param("i", $recipient_id);
            } else {
                $recipient_query = $conn->prepare("SELECT full_name, email FROM students WHERE student_id = ?");
                $recipient_query->bind_param("s", $recipient_id);
            }
            $recipient_query->execute();
            $recipient_data = $recipient_query->get_result()->fetch_assoc();
            
            // Send email notification with copy to sender
            if ($recipient_data['email'] && $sender_data['email']) {
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
    }
}

// Get inbox messages
$inbox = [];
$stmt = $conn->prepare("
    SELECT m.*, 
           CASE 
               WHEN m.sender_type = 'lecturer' THEN l.full_name
               WHEN m.sender_type = 'student' THEN s.full_name
           END as sender_name
    FROM vle_messages m
    LEFT JOIN lecturers l ON m.sender_type = 'lecturer' AND m.sender_id = l.lecturer_id
    LEFT JOIN students s ON m.sender_type = 'student' AND m.sender_id = s.student_id
    WHERE m.recipient_type = 'lecturer' AND m.recipient_id = ?
    ORDER BY m.sent_date DESC
");
$stmt->bind_param("i", $lecturer_id);
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
           END as recipient_name
    FROM vle_messages m
    LEFT JOIN lecturers l ON m.recipient_type = 'lecturer' AND m.recipient_id = l.lecturer_id
    LEFT JOIN students s ON m.recipient_type = 'student' AND m.recipient_id = s.student_id
    WHERE m.sender_type = 'lecturer' AND m.sender_id = ?
    ORDER BY m.sent_date DESC
");
$stmt->bind_param("i", $lecturer_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $sent[] = $row;
}

// Get students from lecturer's courses
$students = [];
$result = $conn->query("
    SELECT DISTINCT s.student_id, s.full_name, vc.course_name
    FROM vle_courses vc
    JOIN vle_enrollments ve ON vc.course_id = ve.course_id
    JOIN students s ON ve.student_id = s.student_id
    WHERE vc.lecturer_id = '$lecturer_id'
    ORDER BY s.full_name
");
while ($row = $result->fetch_assoc()) {
    $students[] = $row;
}

// Get other lecturers
$lecturers = [];
$result = $conn->query("
    SELECT lecturer_id, full_name, department
    FROM lecturers
    WHERE lecturer_id != '$lecturer_id' AND is_active = TRUE
    ORDER BY full_name
");
while ($row = $result->fetch_assoc()) {
    $lecturers[] = $row;
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Messages - VLE System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-success">
        <div class="container-fluid">
            <a class="navbar-brand" href="dashboard.php">VLE System - Lecturer</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="../dashboard.php">Home</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="dashboard.php">Dashboard</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="dashboard.php">My Courses</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="messages.php">Messages</a>
                    </li>
                </ul>
                <div class="navbar-nav">
                    <span class="navbar-text me-3">Welcome, <?php echo htmlspecialchars($user['display_name']); ?></span>
                    <a class="nav-link" href="../logout.php">Logout</a>
                </div>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <h2>Messages</h2>

        <?php if (isset($success)): ?>
            <div class="alert alert-success"><?php echo $success; ?></div>
        <?php endif; ?>
        <?php if (isset($error)): ?>
            <div class="alert alert-danger"><?php echo $error; ?></div>
        <?php endif; ?>

        <div class="row">
            <!-- Compose Message -->
            <div class="col-md-4">
                <div class="card mb-3">
                    <div class="card-header bg-success text-white">
                        <h5>Compose Message</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <div class="mb-3">
                                <label class="form-label">Send To</label>
                                <select class="form-select" name="recipient_type" id="recipient_type" required>
                                    <option value="">Select Type...</option>
                                    <option value="student">Student</option>
                                    <option value="lecturer">Lecturer</option>
                                </select>
                            </div>

                            <div class="mb-3" id="student_select" style="display: none;">
                                <label class="form-label">Select Student</label>
                                <select class="form-select" name="recipient_id_student" id="recipient_id_student">
                                    <option value="">Select Student...</option>
                                    <?php foreach ($students as $student): ?>
                                        <option value="<?php echo htmlspecialchars($student['student_id']); ?>">
                                            <?php echo htmlspecialchars($student['full_name']) . ' - ' . htmlspecialchars($student['course_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="mb-3" id="lecturer_select" style="display: none;">
                                <label class="form-label">Select Lecturer</label>
                                <select class="form-select" name="recipient_id_lecturer" id="recipient_id_lecturer">
                                    <option value="">Select Lecturer...</option>
                                    <?php foreach ($lecturers as $lecturer): ?>
                                        <option value="<?php echo $lecturer['lecturer_id']; ?>">
                                            <?php echo htmlspecialchars($lecturer['full_name']) . ' (' . htmlspecialchars($lecturer['department']) . ')'; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <input type="hidden" name="recipient_id" id="recipient_id">

                            <div class="mb-3">
                                <label class="form-label">Subject</label>
                                <input type="text" class="form-control" name="subject" required>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Message</label>
                                <textarea class="form-control" name="message" rows="4" required></textarea>
                            </div>

                            <button type="submit" name="send_message" class="btn btn-success w-100">Send Message</button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Messages Tabs -->
            <div class="col-md-8">
                <ul class="nav nav-tabs" id="messageTab" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="inbox-tab" data-bs-toggle="tab" data-bs-target="#inbox" type="button">
                            Inbox <span class="badge bg-danger"><?php echo count($inbox); ?></span>
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="sent-tab" data-bs-toggle="tab" data-bs-target="#sent" type="button">
                            Sent <span class="badge bg-secondary"><?php echo count($sent); ?></span>
                        </button>
                    </li>
                </ul>

                <div class="tab-content" id="messageTabContent">
                    <!-- Inbox -->
                    <div class="tab-pane fade show active" id="inbox" role="tabpanel">
                        <div class="list-group">
                            <?php if (empty($inbox)): ?>
                                <div class="list-group-item">No messages in inbox.</div>
                            <?php else: ?>
                                <?php 
                                // Get unique conversations for inbox
                                $inbox_conversations = [];
                                foreach ($inbox as $msg) {
                                    $partner_key = $msg['sender_type'] . '|' . $msg['sender_id'];
                                    if (!isset($inbox_conversations[$partner_key])) {
                                        $inbox_conversations[$partner_key] = [
                                            'partner_type' => $msg['sender_type'],
                                            'partner_id' => $msg['sender_id'],
                                            'partner_name' => $msg['sender_name'],
                                            'last_message' => $msg,
                                            'unread' => !$msg['is_read']
                                        ];
                                    }
                                }
                                ?>
                                <?php foreach ($inbox_conversations as $conv): ?>
                                    <a href="#" class="list-group-item list-group-item-action view-thread" 
                                       data-partner-type="<?php echo $conv['partner_type']; ?>" 
                                       data-partner-id="<?php echo $conv['partner_id']; ?>">
                                        <div class="d-flex w-100 justify-content-between">
                                            <h6 class="mb-1"><?php echo htmlspecialchars($conv['partner_name']); ?> (<?php echo ucfirst($conv['partner_type']); ?>)</h6>
                                            <small><?php echo date('M d, Y H:i', strtotime($conv['last_message']['sent_date'])); ?></small>
                                        </div>
                                        <p class="mb-1"><strong><?php echo htmlspecialchars($conv['last_message']['subject']); ?></strong></p>
                                        <small><?php echo substr(htmlspecialchars($conv['last_message']['message']), 0, 100); ?>...</small>
                                    </a>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Sent -->
                    <div class="tab-pane fade" id="sent" role="tabpanel">
                        <div class="list-group">
                            <?php if (empty($sent)): ?>
                                <div class="list-group-item">No sent messages.</div>
                            <?php else: ?>
                                <?php foreach ($sent as $msg): ?>
                                    <div class="list-group-item">
                                        <div class="d-flex w-100 justify-content-between">
                                            <h6 class="mb-1"><?php echo htmlspecialchars($msg['subject']); ?></h6>
                                            <small><?php echo date('M d, Y H:i', strtotime($msg['sent_date'])); ?></small>
                                        </div>
                                        <p class="mb-1"><strong>To:</strong> <?php echo htmlspecialchars($msg['recipient_name']); ?> (<?php echo ucfirst($msg['recipient_type']); ?>)</p>
                                        <p class="mb-1"><?php echo nl2br(htmlspecialchars($msg['message'])); ?></p>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Thread Modal -->
    <div class="modal fade" id="threadModal" tabindex="-1" aria-labelledby="threadModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="threadModalLabel">Conversation</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="threadContent" style="max-height: 500px; overflow-y: auto;">
                    <!-- Thread messages will be loaded here -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-success" id="quickReplyBtn">Quick Reply</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Handle recipient type selection
        document.getElementById('recipient_type').addEventListener('change', function() {
            const type = this.value;
            const lecturerSelect = document.getElementById('lecturer_select');
            const studentSelect = document.getElementById('student_select');
            const recipientIdLecturer = document.getElementById('recipient_id_lecturer');
            const recipientIdStudent = document.getElementById('recipient_id_student');
            
            if (type === 'lecturer') {
                lecturerSelect.style.display = 'block';
                studentSelect.style.display = 'none';
                recipientIdLecturer.required = true;
                recipientIdStudent.required = false;
            } else if (type === 'student') {
                lecturerSelect.style.display = 'none';
                studentSelect.style.display = 'block';
                recipientIdLecturer.required = false;
                recipientIdStudent.required = true;
            } else {
                lecturerSelect.style.display = 'none';
                studentSelect.style.display = 'none';
                recipientIdLecturer.required = false;
                recipientIdStudent.required = false;
            }
        });

        // Set recipient_id based on selection
        document.getElementById('recipient_id_lecturer').addEventListener('change', function() {
            document.getElementById('recipient_id').value = this.value;
        });
        
        document.getElementById('recipient_id_student').addEventListener('change', function() {
            document.getElementById('recipient_id').value = this.value;
        });

        // Handle reply button clicks
        document.querySelectorAll('.reply-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                const recipientType = this.getAttribute('data-type');
                const recipientId = this.getAttribute('data-id');
                const subject = this.getAttribute('data-subject');
                
                // Set recipient type
                document.getElementById('recipient_type').value = recipientType;
                document.getElementById('recipient_type').dispatchEvent(new Event('change'));
                
                // Set recipient ID after a brief delay to ensure selects are visible
                setTimeout(function() {
                    if (recipientType === 'lecturer') {
                        document.getElementById('recipient_id_lecturer').value = recipientId;
                        document.getElementById('recipient_id').value = recipientId;
                    } else if (recipientType === 'student') {
                        document.getElementById('recipient_id_student').value = recipientId;
                        document.getElementById('recipient_id').value = recipientId;
                    }
                    
                    // Set subject
                    document.querySelector('input[name="subject"]').value = subject;
                    
                    // Scroll to compose section
                    document.querySelector('.card-header h5').scrollIntoView({ behavior: 'smooth', block: 'start' });
                }, 100);
            });
        });

        // Handle thread view clicks
        document.querySelectorAll('.view-thread').forEach(function(link) {
            link.addEventListener('click', function(e) {
                e.preventDefault();
                const partnerType = this.getAttribute('data-partner-type');
                const partnerId = this.getAttribute('data-partner-id');
                
                // Load thread
                loadThread(partnerType, partnerId);
            });
        });

        function loadThread(partnerType, partnerId) {
            // Get all messages from inbox and sent related to this partner
            const allMessages = <?php echo json_encode(array_merge($inbox, $sent)); ?>;
            const lecturerId = <?php echo $lecturer_id; ?>;
            
            // Filter messages for this conversation
            const threadMessages = allMessages.filter(msg => 
                (msg.sender_type === partnerType && msg.sender_id == partnerId) ||
                (msg.recipient_type === partnerType && msg.recipient_id == partnerId)
            );
            
            // Sort by date
            threadMessages.sort((a, b) => new Date(a.sent_date) - new Date(b.sent_date));
            
            // Build thread HTML
            let html = '<div class="conversation-thread">';
            threadMessages.forEach(msg => {
                const isSender = msg.sender_type === 'lecturer' && msg.sender_id == lecturerId;
                const className = isSender ? 'sent' : 'received';
                const bgColor = isSender ? 'bg-success text-white' : 'bg-light';
                const align = isSender ? 'ms-auto' : 'me-auto';
                const sender = isSender ? 'You' : msg.sender_name || msg.recipient_name;
                
                html += `
                    <div class="message-bubble ${align}" style="max-width: 70%; margin-bottom: 15px;">
                        <div class="card ${bgColor}">
                            <div class="card-body">
                                <h6 class="card-subtitle mb-2">${sender}</h6>
                                <p class="card-text"><strong>${msg.subject}</strong></p>
                                <p class="card-text">${msg.message.replace(/\n/g, '<br>')}</p>
                                <small class="text-muted">${new Date(msg.sent_date).toLocaleString()}</small>
                            </div>
                        </div>
                    </div>
                `;
            });
            html += '</div>';
            
            document.getElementById('threadContent').innerHTML = html;
            
            // Show modal
            const threadModal = new bootstrap.Modal(document.getElementById('threadModal'));
            threadModal.show();
            
            // Setup quick reply
            document.getElementById('quickReplyBtn').onclick = function() {
                threadModal.hide();
                document.getElementById('recipient_type').value = partnerType;
                document.getElementById('recipient_type').dispatchEvent(new Event('change'));
                
                setTimeout(function() {
                    if (partnerType === 'lecturer') {
                        document.getElementById('recipient_id_lecturer').value = partnerId;
                        document.getElementById('recipient_id').value = partnerId;
                    } else {
                        document.getElementById('recipient_id_student').value = partnerId;
                        document.getElementById('recipient_id').value = partnerId;
                    }
                    document.querySelector('.card-header h5').scrollIntoView({ behavior: 'smooth' });
                }, 100);
            };
        }
    </script>
</body>
</html>
