<?php
// lecturer/live_classroom.php - Live classroom/meeting management for lecturers
require_once '../includes/auth.php';
requireLogin();
requireRole(['lecturer']);

$conn = getDbConnection();

// Create live session tables if they don't exist
// Temporarily disable foreign key checks to avoid constraint errors
$conn->query("SET FOREIGN_KEY_CHECKS=0");

$create_tables = "
CREATE TABLE IF NOT EXISTS vle_live_sessions (
    session_id INT PRIMARY KEY AUTO_INCREMENT,
    course_id INT NOT NULL,
    lecturer_id VARCHAR(50) NOT NULL,
    session_name VARCHAR(255) NOT NULL,
    session_code VARCHAR(50) UNIQUE NOT NULL,
    status ENUM('pending', 'active', 'completed') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    started_at TIMESTAMP NULL,
    ended_at TIMESTAMP NULL,
    max_participants INT DEFAULT 50,
    meeting_url VARCHAR(500),
    recording_url VARCHAR(500) DEFAULT NULL,
    INDEX idx_course (course_id),
    INDEX idx_lecturer (lecturer_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vle_session_participants (
    participant_id INT PRIMARY KEY AUTO_INCREMENT,
    session_id INT NOT NULL,
    student_id VARCHAR(50) NOT NULL,
    joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    left_at TIMESTAMP NULL,
    status ENUM('invited', 'joined', 'completed') DEFAULT 'invited',
    INDEX idx_session (session_id),
    INDEX idx_student (student_id),
    UNIQUE (session_id, student_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vle_session_invites (
    invite_id INT PRIMARY KEY AUTO_INCREMENT,
    session_id INT NOT NULL,
    student_id VARCHAR(50) NOT NULL,
    invited_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    viewed_at TIMESTAMP NULL,
    status ENUM('pending', 'accepted', 'declined') DEFAULT 'pending',
    INDEX idx_session (session_id),
    INDEX idx_student (student_id),
    UNIQUE (session_id, student_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
";

// Execute table creation
if (mysqli_multi_query($conn, $create_tables)) {
    // Clear all results from multi_query
    do {
        if ($result = $conn->store_result()) {
            $result->free();
        }
    } while ($conn->next_result());
}

// Re-enable foreign key checks
$conn->query("SET FOREIGN_KEY_CHECKS=1");

$lecturer_id = $_SESSION['vle_related_id'];

if (!$lecturer_id) {
    die("Error: Lecturer ID not found.");
}

// Get lecturer's courses
$courses = [];
$result = $conn->query("
    SELECT * FROM vle_courses 
    WHERE lecturer_id = '$lecturer_id'
    ORDER BY course_name
");

while ($row = $result->fetch_assoc()) {
    $courses[] = $row;
}

// Get current/active sessions
$active_sessions = [];
$result = $conn->query("
    SELECT vls.*, vcs.course_name,
           COUNT(DISTINCT CASE WHEN vsp.status = 'joined' THEN vsp.participant_id END) as active_participants,
           COUNT(DISTINCT vsp.participant_id) as total_participants
    FROM vle_live_sessions vls
    JOIN vle_courses vcs ON vls.course_id = vcs.course_id
    LEFT JOIN vle_session_participants vsp ON vls.session_id = vsp.session_id
    WHERE vls.lecturer_id = '$lecturer_id' AND vls.status = 'active'
    GROUP BY vls.session_id
    ORDER BY vls.started_at DESC
");

while ($row = $result->fetch_assoc()) {
    $active_sessions[] = $row;
}

$user = getCurrentUser();

// Ensure recording_url column exists
$conn->query("ALTER TABLE vle_live_sessions ADD COLUMN IF NOT EXISTS recording_url VARCHAR(500) DEFAULT NULL");

// Get recorded sessions for this lecturer
$recorded_sessions = [];
$result = $conn->query("
    SELECT vls.*, vcs.course_name,
           COUNT(DISTINCT vsp.participant_id) as total_participants
    FROM vle_live_sessions vls
    JOIN vle_courses vcs ON vls.course_id = vcs.course_id
    LEFT JOIN vle_session_participants vsp ON vls.session_id = vsp.session_id
    WHERE vls.lecturer_id = '$lecturer_id' 
      AND vls.status = 'completed'
      AND vls.recording_url IS NOT NULL
      AND vls.recording_url != ''
    GROUP BY vls.session_id
    ORDER BY vls.ended_at DESC
");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $recorded_sessions[] = $row;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Live Classroom - VLE System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
    <style>
        .live-badge { 
            display: inline-block;
            background: #dc3545;
            color: white;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: bold;
            animation: pulse 1.5s infinite;
        }
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.6; }
        }
        .session-card {
            border: none;
            border-left: 4px solid var(--vle-accent);
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
        }
        .session-card:hover {
            box-shadow: 0 4px 16px rgba(0,0,0,0.15);
        }
        .participant-badge {
            background: var(--vle-accent);
            color: white;
            padding: 8px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
        }
        .meeting-container {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: var(--vle-radius-lg);
            padding: 40px 20px;
            color: white;
            text-align: center;
            margin-bottom: 30px;
        }
        .start-session-form {
            background: #f8f9fa;
            padding: 25px;
            border-radius: var(--vle-radius-lg);
            border: 1px solid #dee2e6;
        }
        .recorded-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            background: #e74c3c;
            color: white;
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
        }
        .recording-card {
            border: 1px solid #dee2e6;
            border-radius: var(--vle-radius-md);
            padding: 16px;
            background: #fff;
            border-left: 4px solid #e74c3c;
            transition: all 0.3s ease;
        }
        .recording-card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        .recording-player video {
            width: 100%;
            border-radius: 8px;
            background: #000;
        }
    </style>
</head>
<body>
    <?php 
    $currentPage = 'live_classroom';
    $pageTitle = 'Live Classroom';
    include 'header_nav.php'; 
    ?>

    <div class="vle-content">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2 class="vle-page-title"><i class="bi bi-camera-video me-2"></i>Live Classroom</h2>
            <a href="dashboard.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Back</a>
        </div>

        <!-- Meeting Container -->
        <div class="meeting-container">
            <h4 class="mb-3"><i class="bi bi-camera-video me-2"></i>Start Live Class</h4>
            <p class="mb-0">Connect with your students in real-time using video conferencing</p>
        </div>

        <!-- Start New Session -->
        <div class="card mb-4">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="bi bi-plus-circle me-2"></i>Start New Live Session</h5>
            </div>
            <div class="card-body">
                <form id="startSessionForm" class="start-session-form">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label"><i class="bi bi-book me-1"></i>Select Course</label>
                            <select name="course_id" id="courseSelect" class="form-select" required>
                                <option value="">-- Choose a course --</option>
                                <?php foreach ($courses as $course): ?>
                                    <option value="<?php echo $course['course_id']; ?>">
                                        <?php echo htmlspecialchars($course['course_name']); ?> (<?php echo htmlspecialchars($course['course_code']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label"><i class="bi bi-chat-left-dots me-1"></i>Session Topic/Name</label>
                            <input type="text" name="session_name" id="sessionName" class="form-control" 
                                   placeholder="e.g., Lecture 5: Advanced Topics" required>
                        </div>
                    </div>
                    <div class="alert alert-info" role="alert">
                        <i class="bi bi-info-circle me-2"></i>
                        <strong>Note:</strong> When you start a live session, all enrolled students will receive an invite notification to join the classroom.
                    </div>
                    <button type="submit" class="btn btn-success btn-lg">
                        <i class="bi bi-play-circle me-2"></i>Start Live Session
                    </button>
                </form>
            </div>
        </div>

        <!-- Active Sessions -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">
                    <i class="bi bi-list me-2"></i>Active Live Sessions
                    <?php if (!empty($active_sessions)): ?>
                        <span class="live-badge ms-2">
                            <i class="bi bi-circle-fill me-1"></i>LIVE
                        </span>
                    <?php endif; ?>
                </h5>
            </div>
            <div class="card-body">
                <?php if (empty($active_sessions)): ?>
                    <div class="alert alert-secondary">
                        <i class="bi bi-info-circle me-2"></i>No active sessions at the moment.
                    </div>
                <?php else: ?>
                    <div class="row">
                        <?php foreach ($active_sessions as $session): ?>
                            <div class="col-md-6 mb-3">
                                <div class="card session-card">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between align-items-start mb-2">
                                            <h6 class="card-title"><?php echo htmlspecialchars($session['session_name']); ?></h6>
                                            <span class="live-badge">LIVE</span>
                                        </div>
                                        <p class="text-muted mb-2">
                                            <i class="bi bi-book me-1"></i><?php echo htmlspecialchars($session['course_name']); ?>
                                        </p>
                                        <p class="mb-2">
                                            <span class="participant-badge">
                                                <i class="bi bi-people me-1"></i><?php echo $session['active_participants']; ?> Active / <?php echo $session['total_participants']; ?> Invited
                                            </span>
                                        </p>
                                        <p class="text-muted small mb-3">
                                            <i class="bi bi-clock me-1"></i>Started: <?php echo date('M d, Y H:i', strtotime($session['started_at'])); ?>
                                        </p>
                                        <div class="btn-group w-100 mb-2" role="group">
                                            <a href="live_room.php?session_id=<?php echo $session['session_id']; ?>"
                                               class="btn btn-primary btn-sm">
                                                <i class="bi bi-camera-video me-1"></i>Join Classroom
                                            </a>
                                            <button type="button" class="btn btn-info btn-sm" 
                                                    onclick="viewParticipants(<?php echo $session['session_id']; ?>)">
                                                <i class="bi bi-people me-1"></i>Participants
                                            </button>
                                            <button type="button" class="btn btn-danger btn-sm" 
                                                    onclick="endSession(<?php echo $session['session_id']; ?>)">
                                                <i class="bi bi-stop-circle me-1"></i>End
                                            </button>
                                        </div>
                                        <button type="button" class="btn btn-outline-success btn-sm w-100"
                                                onclick="openAddStudent(<?php echo $session['session_id']; ?>, '<?php echo htmlspecialchars(addslashes($session['course_name'])); ?>')">
                                            <i class="bi bi-person-plus me-1"></i>Add Student / Participants
                                        </button>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Recorded Sessions Section -->
        <div class="card mt-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="bi bi-collection-play me-2"></i>Recorded Sessions
                    <?php if (!empty($recorded_sessions)): ?>
                        <span class="recorded-badge ms-2"><?php echo count($recorded_sessions); ?></span>
                    <?php endif; ?>
                </h5>
            </div>
            <div class="card-body">
                <?php if (empty($recorded_sessions)): ?>
                    <div class="alert alert-secondary">
                        <i class="bi bi-info-circle me-2"></i>No recorded sessions yet. Use the record button during a live session to create recordings that students can watch later.
                    </div>
                <?php else: ?>
                    <div class="row">
                        <?php foreach ($recorded_sessions as $rec): ?>
                            <div class="col-md-6 mb-3" id="recording-card-<?php echo $rec['session_id']; ?>">
                                <div class="recording-card">
                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                        <h6 class="mb-0"><?php echo htmlspecialchars($rec['session_name']); ?></h6>
                                        <span class="recorded-badge"><i class="bi bi-record-circle-fill"></i> Recorded</span>
                                    </div>
                                    <p class="text-muted small mb-1">
                                        <i class="bi bi-book me-1"></i><?php echo htmlspecialchars($rec['course_name']); ?>
                                    </p>
                                    <p class="text-muted small mb-1">
                                        <i class="bi bi-calendar me-1"></i><?php echo date('M d, Y H:i', strtotime($rec['ended_at'] ?? $rec['created_at'])); ?>
                                    </p>
                                    <p class="text-muted small mb-2">
                                        <i class="bi bi-people me-1"></i><?php echo $rec['total_participants']; ?> participants
                                    </p>
                                    <div class="d-flex gap-2">
                                        <button class="btn btn-sm btn-outline-danger" onclick="previewRecording('<?php echo htmlspecialchars($rec['recording_url']); ?>', '<?php echo htmlspecialchars(addslashes($rec['session_name'])); ?>')">
                                            <i class="bi bi-play-circle me-1"></i>Preview
                                        </button>
                                        <button class="btn btn-sm btn-outline-dark" onclick="deleteRecording(<?php echo $rec['session_id']; ?>, '<?php echo htmlspecialchars(addslashes($rec['session_name'])); ?>')">
                                            <i class="bi bi-trash me-1"></i>Delete
                                        </button>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Recording Preview Modal -->
    <div class="modal fade" id="recordingModal" tabindex="-1" aria-labelledby="recordingModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="recordingModalLabel"><i class="bi bi-play-circle me-2"></i>Session Recording</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body recording-player p-0">
                    <video id="recordingVideo" controls style="width:100%;display:block;border-radius:0 0 8px 8px;">
                        Your browser does not support the video tag.
                    </video>
                </div>
                <div class="modal-footer">
                    <a id="recordingDownloadLink" href="#" class="btn btn-sm btn-outline-primary" download>
                        <i class="bi bi-download me-1"></i>Download Recording
                    </a>
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Participants Modal -->
    <div class="modal fade" id="participantsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-people me-2"></i>Session Participants</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="participantsList"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Student Modal -->
    <div class="modal fade" id="addStudentModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title"><i class="bi bi-person-plus me-2"></i>Add Student to Session</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Search Student</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-search"></i></span>
                            <input type="text" id="studentSearchInput" class="form-control" 
                                   placeholder="Type student name, ID or email..." autocomplete="off">
                        </div>
                        <small class="text-muted">Showing enrolled students for <strong id="addStudentCourseName"></strong></small>
                    </div>
                    <div id="studentSearchResults" style="max-height:350px;overflow-y:auto;"></div>
                    <div id="addStudentStatus" class="mt-2"></div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Start new session
        document.getElementById('startSessionForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            
            const courseId = document.getElementById('courseSelect').value;
            const sessionName = document.getElementById('sessionName').value;
            
            if (!courseId || !sessionName) {
                alert('Please fill in all fields');
                return;
            }
            
            const btn = e.target.querySelector('button[type="submit"]');
            const originalText = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>Starting...';
            
            try {
                const response = await fetch('../api/live_session_api.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: `action=start_session&course_id=${courseId}&session_name=${encodeURIComponent(sessionName)}`
                });
                
                const text = await response.text();
                let data;
                try {
                    data = JSON.parse(text);
                } catch (parseErr) {
                    console.error('API response (not JSON):', text);
                    alert('Server returned an invalid response (HTTP ' + response.status + '). Check the error log or try again.');
                    return;
                }
                
                if (data.success) {
                    // Open the built-in live classroom
                    window.location.href = 'live_room.php?session_id=' + data.session_id;
                } else {
                    alert('Error: ' + data.message);
                }
            } catch (error) {
                alert('Error starting session: ' + error.message);
            } finally {
                btn.disabled = false;
                btn.innerHTML = originalText;
            }
        });
        
        // View participants
        let currentParticipantsSessionId = null;
        async function viewParticipants(sessionId) {
            currentParticipantsSessionId = sessionId;
            try {
                const response = await fetch(`../api/live_session_api.php?action=get_participants&session_id=${sessionId}`);
                const data = await response.json();
                
                if (data.success) {
                    let html = '<table class="table table-sm"><thead><tr><th>Student</th><th>Status</th><th>Joined</th><th>Action</th></tr></thead><tbody>';
                    
                    if (data.participants.length === 0) {
                        html += '<tr><td colspan="4" class="text-center text-muted">No participants yet</td></tr>';
                    } else {
                        data.participants.forEach(p => {
                            const status = p.status === 'joined' ? '<span class="badge bg-success">Joined</span>' : '<span class="badge bg-secondary">Invited</span>';
                            const joined = p.joined_at ? new Date(p.joined_at).toLocaleString() : '-';
                            html += `<tr id="participant-row-${p.student_id}">
                                <td>${p.full_name}<br><small class="text-muted">${p.student_id}</small></td>
                                <td>${status}</td>
                                <td>${joined}</td>
                                <td><button class="btn btn-outline-danger btn-sm" onclick="removeParticipant(${sessionId}, '${p.student_id}', this)" title="Remove"><i class="bi bi-x-circle"></i></button></td>
                            </tr>`;
                        });
                    }
                    
                    html += '</tbody></table>';
                    html += `<button class="btn btn-success btn-sm mt-2" onclick="bootstrap.Modal.getInstance(document.getElementById('participantsModal')).hide(); openAddStudent(${sessionId}, '')"><i class="bi bi-person-plus me-1"></i>Add Student</button>`;
                    document.getElementById('participantsList').innerHTML = html;
                    new bootstrap.Modal(document.getElementById('participantsModal')).show();
                }
            } catch (error) {
                alert('Error loading participants: ' + error.message);
            }
        }

        // Remove participant
        async function removeParticipant(sessionId, studentId, btn) {
            if (!confirm('Remove this student from the session?')) return;
            btn.disabled = true;
            try {
                const response = await fetch('../api/live_session_api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
                    body: `action=remove_participant&session_id=${sessionId}&student_id=${studentId}`
                });
                const data = await response.json();
                if (data.success) {
                    const row = btn.closest('tr');
                    row.style.transition = 'opacity 0.3s';
                    row.style.opacity = '0';
                    setTimeout(() => row.remove(), 300);
                } else {
                    alert('Error: ' + data.message);
                    btn.disabled = false;
                }
            } catch (e) {
                alert('Error: ' + e.message);
                btn.disabled = false;
            }
        }

        // Add Student modal
        let addStudentSessionId = null;
        let searchTimeout = null;

        function openAddStudent(sessionId, courseName) {
            addStudentSessionId = sessionId;
            document.getElementById('addStudentCourseName').textContent = courseName || 'this course';
            document.getElementById('studentSearchInput').value = '';
            document.getElementById('studentSearchResults').innerHTML = '<p class="text-muted text-center py-3">Type a name, student ID or email to search...</p>';
            document.getElementById('addStudentStatus').innerHTML = '';
            const modal = new bootstrap.Modal(document.getElementById('addStudentModal'));
            modal.show();
            setTimeout(() => document.getElementById('studentSearchInput').focus(), 300);
        }

        document.getElementById('studentSearchInput').addEventListener('input', function() {
            clearTimeout(searchTimeout);
            const q = this.value.trim();
            if (q.length < 1) {
                document.getElementById('studentSearchResults').innerHTML = '<p class="text-muted text-center py-3">Type a name, student ID or email to search...</p>';
                return;
            }
            searchTimeout = setTimeout(() => searchStudents(q), 300);
        });

        async function searchStudents(query) {
            document.getElementById('studentSearchResults').innerHTML = '<div class="text-center py-3"><span class="spinner-border spinner-border-sm"></span> Searching...</div>';
            try {
                const response = await fetch(`../api/live_session_api.php?action=search_students&session_id=${addStudentSessionId}&q=${encodeURIComponent(query)}`);
                const data = await response.json();
                if (data.success) {
                    if (data.students.length === 0) {
                        document.getElementById('studentSearchResults').innerHTML = '<p class="text-muted text-center py-3">No students found matching your search.</p>';
                        return;
                    }
                    let html = '<div class="list-group">';
                    data.students.forEach(s => {
                        const added = parseInt(s.already_added);
                        html += `<div class="list-group-item d-flex justify-content-between align-items-center" id="student-row-${s.student_id}">
                            <div>
                                <strong>${escHtml(s.full_name)}</strong><br>
                                <small class="text-muted">${escHtml(s.student_id)} &bull; ${escHtml(s.email || 'No email')}</small>
                            </div>
                            ${added 
                                ? '<span class="badge bg-secondary"><i class="bi bi-check-circle me-1"></i>Already Added</span>' 
                                : `<button class="btn btn-success btn-sm" onclick="addStudentToSession('${s.student_id}', this)"><i class="bi bi-plus-circle me-1"></i>Add</button>`
                            }
                        </div>`;
                    });
                    html += '</div>';
                    document.getElementById('studentSearchResults').innerHTML = html;
                }
            } catch (e) {
                document.getElementById('studentSearchResults').innerHTML = '<p class="text-danger text-center">Error: ' + e.message + '</p>';
            }
        }

        function escHtml(str) {
            const d = document.createElement('div');
            d.textContent = str || '';
            return d.innerHTML;
        }

        async function addStudentToSession(studentId, btn) {
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
            try {
                const response = await fetch('../api/live_session_api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
                    body: `action=add_participant&session_id=${addStudentSessionId}&student_id=${studentId}`
                });
                const data = await response.json();
                if (data.success) {
                    btn.outerHTML = '<span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Added & Notified</span>';
                    document.getElementById('addStudentStatus').innerHTML = '<div class="alert alert-success py-2 px-3 small mb-0"><i class="bi bi-check-circle me-1"></i>Student added and notified via email</div>';
                    setTimeout(() => { document.getElementById('addStudentStatus').innerHTML = ''; }, 3000);
                } else {
                    alert('Error: ' + data.message);
                    btn.disabled = false;
                    btn.innerHTML = '<i class="bi bi-plus-circle me-1"></i>Add';
                }
            } catch (e) {
                alert('Error: ' + e.message);
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-plus-circle me-1"></i>Add';
            }
        }
        
        // Preview recording
        function previewRecording(url, title) {
            const video = document.getElementById('recordingVideo');
            const label = document.getElementById('recordingModalLabel');
            const dlLink = document.getElementById('recordingDownloadLink');
            label.innerHTML = '<i class="bi bi-play-circle me-2"></i>' + title;
            const fullUrl = '../' + url;
            video.src = fullUrl;
            dlLink.href = fullUrl;
            dlLink.download = title.replace(/[^a-zA-Z0-9\s]/g, '') + '.webm';
            const modal = new bootstrap.Modal(document.getElementById('recordingModal'));
            modal.show();

            document.getElementById('recordingModal').addEventListener('hidden.bs.modal', function () {
                video.pause();
                video.src = '';
            }, { once: true });
        }

        // Delete recording
        async function deleteRecording(sessionId, sessionName) {
            if (!confirm('Delete recording for "' + sessionName + '"?\n\nThis will permanently remove the recording file. Students will no longer be able to watch it.')) {
                return;
            }

            try {
                const response = await fetch('../api/live_session_api.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: `action=delete_recording&session_id=${sessionId}`
                });

                const data = await response.json();

                if (data.success) {
                    // Remove the card from UI with animation
                    const card = document.getElementById('recording-card-' + sessionId);
                    if (card) {
                        card.style.transition = 'opacity 0.3s, transform 0.3s';
                        card.style.opacity = '0';
                        card.style.transform = 'scale(0.95)';
                        setTimeout(() => card.remove(), 300);
                    }
                    // Update badge count
                    const badges = document.querySelectorAll('.recorded-badge');
                    badges.forEach(b => {
                        const num = parseInt(b.textContent);
                        if (!isNaN(num) && num > 0) b.textContent = num - 1;
                    });
                } else {
                    alert('Error: ' + data.message);
                }
            } catch (error) {
                alert('Error deleting recording: ' + error.message);
            }
        }

        // End session
        async function endSession(sessionId) {
            if (!confirm('Are you sure you want to end this live session?')) {
                return;
            }
            
            try {
                const response = await fetch('../api/live_session_api.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: `action=end_session&session_id=${sessionId}`
                });
                
                const data = await response.json();
                
                if (data.success) {
                    alert('Session ended successfully');
                    location.reload();
                } else {
                    alert('Error: ' + data.message);
                }
            } catch (error) {
                alert('Error ending session: ' + error.message);
            }
        }
    </script>
</body>
</html>
