<?php
// api/live_session_api.php - API for live session management
// Suppress all output except JSON
ini_set('display_errors', '0');
error_reporting(E_ALL);
ini_set('log_errors', '1');

header('Content-Type: application/json');

// Global error handler to always return JSON instead of empty/HTML response
set_error_handler(function($severity, $message, $file, $line) {
    // Don't handle suppressed errors (@-operator)
    if (!(error_reporting() & $severity)) return true;
    throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(function($e) {
    // Clear any buffered output
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    error_log('live_session_api fatal: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
    exit;
});

register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        error_log('live_session_api shutdown: ' . $error['message'] . ' in ' . $error['file'] . ':' . $error['line']);
        echo json_encode(['success' => false, 'message' => 'Server error: ' . $error['message']]);
    }
});

ob_start();

require_once '../includes/auth.php';
require_once '../includes/email.php';
ob_end_clean();

// Check login - return JSON error instead of redirecting
if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Not logged in. Please log in and try again.']);
    exit;
}

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

$user = getCurrentUser();
$action = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : '');

// Get active Zoom settings (safe — returns null if table doesn't exist)
function getActiveZoomSettings($conn) {
    try {
        // Check if zoom_settings table exists first
        $table_check = $conn->query("SHOW TABLES LIKE 'zoom_settings'");
        if (!$table_check || $table_check->num_rows === 0) {
            return null;
        }
        $stmt = $conn->prepare("SELECT * FROM zoom_settings WHERE is_active = TRUE LIMIT 1");
        if (!$stmt) return null;
        $stmt->execute();
        $result = $stmt->get_result();
        $zoom_settings = $result->num_rows > 0 ? $result->fetch_assoc() : null;
        $stmt->close();
        return $zoom_settings;
    } catch (Throwable $e) {
        error_log('getActiveZoomSettings error: ' . $e->getMessage());
        return null;
    }
}

// Generate unique session code for Zoom (numeric ID)
function generateSessionCode() {
    // Generate a unique numeric code for Zoom meeting ID
    return rand(100000000, 999999999);
}

// Create internal meeting URL for the built-in live classroom
function createZoomMeetingUrl($session_id, $participant_name = 'Participant') {
    return "live_room.php?session_id=" . $session_id;
}

// Start a live session
if ($action === 'start_session' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $course_id = isset($_POST['course_id']) ? (int)$_POST['course_id'] : 0;
        $session_name = isset($_POST['session_name']) ? trim($_POST['session_name']) : '';
        
        if (!$course_id || !$session_name) {
            echo json_encode(['success' => false, 'message' => 'Missing required fields']);
            exit;
        }
        
        // Get the current user's ID (should be stored in session)
        $lecturer_user_id = $user['user_id'];
        
        // Verify lecturer owns this course by checking related_lecturer_id
        $stmt = $conn->prepare("
            SELECT l.lecturer_id 
            FROM lecturers l 
            JOIN users u ON l.lecturer_id = u.related_lecturer_id 
            WHERE u.user_id = ? AND l.lecturer_id IN (SELECT lecturer_id FROM vle_courses WHERE course_id = ?)
        ");
        $stmt->bind_param("ii", $lecturer_user_id, $course_id);
        $stmt->execute();
        
        if ($stmt->get_result()->num_rows === 0) {
            echo json_encode(['success' => false, 'message' => 'Unauthorized or course not found']);
            exit;
        }
        
        $session_code = generateSessionCode();
        
        // Zoom settings no longer required — using built-in WebRTC classroom
        // Keep zoom_settings check optional for backward compatibility
        $zoom_settings = null;
        try {
            $zoom_settings = getActiveZoomSettings($conn);
        } catch (Throwable $e) {
            // Ignore — not needed for built-in classroom
        }
        
        $meeting_url = createZoomMeetingUrl($session_code);
        
        // Convert lecturer_user_id to string for storage in vle_live_sessions
        $lecturer_id_str = (string)$lecturer_user_id;
        
        // Create session
        $stmt = $conn->prepare("
            INSERT INTO vle_live_sessions 
            (course_id, lecturer_id, session_name, session_code, status, started_at, meeting_url)
            VALUES (?, ?, ?, ?, 'active', NOW(), ?)
        ");
        
        $stmt->bind_param("issss", $course_id, $lecturer_id_str, $session_name, $session_code, $meeting_url);
        
        if ($stmt->execute()) {
            $session_id = $stmt->insert_id;
            
            // Update meeting_url with actual session_id now that we have it
            $internal_url = "live_room.php?session_id=" . $session_id;
            $conn->query("UPDATE vle_live_sessions SET meeting_url = '$internal_url' WHERE session_id = $session_id");
            
            // Build full student join URL for email
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $base_path = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/\\');
            $student_join_url = $protocol . '://' . $host . $base_path . '/student/live_room.php?session_id=' . $session_id;
            $student_invites_url = $protocol . '://' . $host . $base_path . '/student/live_invites.php';
            
            // Register lecturer as first participant (host)
            $lecturer_student_id = (string)$lecturer_user_id;
            $stmt_lecturer = $conn->prepare("
                INSERT INTO vle_session_participants 
                (session_id, student_id, status, joined_at)
                VALUES (?, ?, 'joined', NOW())
            ");
            $stmt_lecturer->bind_param("is", $session_id, $lecturer_student_id);
            @$stmt_lecturer->execute();
            $stmt_lecturer->close();
            
            // Get all enrolled students for this course
            $result = $conn->query("
                SELECT DISTINCT ve.student_id
                FROM vle_enrollments ve
                WHERE ve.course_id = $course_id
            ");
            
            // Get course and lecturer info for email
            $course_info = $conn->query("SELECT c.course_name, l.full_name as lecturer_name FROM vle_courses c JOIN lecturers l ON c.lecturer_id = l.lecturer_id WHERE c.course_id = $course_id")->fetch_assoc();
            
            // Send invites to all students
            if ($result) {
                while ($student = $result->fetch_assoc()) {
                    $student_id = $student['student_id'];
                    
                    // Insert invite
                    try {
                        $stmt2 = $conn->prepare("
                            INSERT INTO vle_session_invites 
                            (session_id, student_id, status)
                            VALUES (?, ?, 'pending')
                        ");
                        if ($stmt2) {
                            $stmt2->bind_param("is", $session_id, $student_id);
                            @$stmt2->execute();
                            $stmt2->close();
                        }
                    } catch (Throwable $e) { /* ignore duplicate */ }
                    
                    // Insert participant record
                    try {
                        $stmt3 = $conn->prepare("
                            INSERT INTO vle_session_participants 
                            (session_id, student_id, status)
                            VALUES (?, ?, 'invited')
                        ");
                        if ($stmt3) {
                            $stmt3->bind_param("is", $session_id, $student_id);
                            @$stmt3->execute();
                            $stmt3->close();
                        }
                    } catch (Throwable $e) { /* ignore duplicate */ }
                    
                    // Send email notification to student (wrapped in try/catch so email failure doesn't kill the response)
                    try {
                        if (function_exists('isEmailEnabled') && isEmailEnabled() && $course_info) {
                            $student_info_stmt = $conn->prepare("SELECT full_name, email FROM students WHERE student_id = ?");
                            if ($student_info_stmt) {
                                $student_info_stmt->bind_param("s", $student_id);
                                $student_info_stmt->execute();
                                $student_info = $student_info_stmt->get_result()->fetch_assoc();
                                $student_info_stmt->close();
                                
                                if ($student_info && $student_info['email'] && function_exists('sendLiveSessionInviteEmail')) {
                                    sendLiveSessionInviteEmail(
                                        $student_info['email'],
                                        $student_info['full_name'],
                                        $course_info['lecturer_name'],
                                        $course_info['course_name'],
                                        $session_name,
                                        date('Y-m-d'),
                                        date('H:i:s'),
                                        $student_join_url,
                                        '',
                                        $student_invites_url
                                    );
                                }
                            }
                        }
                    } catch (Throwable $e) {
                        error_log('Email notification error for student ' . $student_id . ': ' . $e->getMessage());
                    }
                }
            }
            
            echo json_encode([
                'success' => true, 
                'message' => 'Live session started',
                'session_id' => $session_id,
                'session_code' => $session_code,
                'meeting_url' => $meeting_url,
                'auto_open' => true
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to create session: ' . $conn->error]);
        }
    } catch (Throwable $e) {
        error_log('start_session error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
    exit;
}

// Upload session recording (lecturer only)
if ($action === 'upload_recording' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Increase PHP limits for large recording uploads
    @ini_set('upload_max_filesize', '256M');
    @ini_set('post_max_size', '260M');
    @ini_set('max_execution_time', '300');
    @ini_set('max_input_time', '300');
    @ini_set('memory_limit', '512M');
    
    $session_id = isset($_POST['session_id']) ? (int)$_POST['session_id'] : 0;
    
    if (!$session_id) {
        echo json_encode(['success' => false, 'message' => 'Missing session ID']);
        exit;
    }
    
    // Verify lecturer owns this session
    $stmt = $conn->prepare("SELECT session_id, course_id FROM vle_live_sessions WHERE session_id = ? AND lecturer_id = ?");
    $stmt->bind_param("is", $session_id, $user['user_id']);
    $stmt->execute();
    $session_row = $stmt->get_result()->fetch_assoc();
    
    if (!$session_row) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized or session not found. User ID: ' . $user['user_id']]);
        exit;
    }
    
    // Check file upload with detailed error messages
    if (!isset($_FILES['recording']) || $_FILES['recording']['error'] !== UPLOAD_ERR_OK) {
        $error_code = $_FILES['recording']['error'] ?? 'No file received';
        $error_messages = [
            UPLOAD_ERR_INI_SIZE => 'File exceeds PHP upload_max_filesize (' . ini_get('upload_max_filesize') . ')',
            UPLOAD_ERR_FORM_SIZE => 'File exceeds form MAX_FILE_SIZE',
            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder on server',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the upload',
        ];
        $error_msg = is_numeric($error_code) ? ($error_messages[$error_code] ?? "Unknown upload error code: $error_code") : $error_code;
        echo json_encode(['success' => false, 'message' => 'Upload error: ' . $error_msg]);
        exit;
    }
    
    $file = $_FILES['recording'];
    $max_size = 500 * 1024 * 1024; // 500MB max
    
    if ($file['size'] > $max_size) {
        echo json_encode(['success' => false, 'message' => 'File too large (max 500MB)']);
        exit;
    }
    
    // Create recordings directory
    $upload_dir = __DIR__ . '/../uploads/recordings/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    
    // Generate unique filename
    $ext = 'webm';
    $filename = 'session_' . $session_id . '_' . date('Ymd_His') . '.' . $ext;
    $filepath = $upload_dir . $filename;
    
    if (move_uploaded_file($file['tmp_name'], $filepath)) {
        // Ensure recording_url column exists
        $conn->query("ALTER TABLE vle_live_sessions ADD COLUMN IF NOT EXISTS recording_url VARCHAR(500) DEFAULT NULL AFTER meeting_url");
        
        // Save recording URL to session
        $recording_url = 'uploads/recordings/' . $filename;
        $stmt = $conn->prepare("UPDATE vle_live_sessions SET recording_url = ? WHERE session_id = ?");
        $stmt->bind_param("si", $recording_url, $session_id);
        $stmt->execute();
        
        echo json_encode(['success' => true, 'message' => 'Recording saved', 'recording_url' => $recording_url]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to save file']);
    }
    exit;
}

// Delete a recording
if ($action === 'delete_recording' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $session_id = isset($_POST['session_id']) ? (int)$_POST['session_id'] : 0;
    
    // Verify lecturer owns this session
    $stmt = $conn->prepare("SELECT session_id, recording_url FROM vle_live_sessions WHERE session_id = ? AND lecturer_id = ?");
    $stmt->bind_param("is", $session_id, $user['user_id']);
    $stmt->execute();
    $session = $stmt->get_result()->fetch_assoc();
    
    if (!$session) {
        echo json_encode(['success' => false, 'message' => 'Session not found or unauthorized']);
        exit;
    }
    
    if (empty($session['recording_url'])) {
        echo json_encode(['success' => false, 'message' => 'No recording found for this session']);
        exit;
    }
    
    // Delete the file from disk
    $file_path = __DIR__ . '/../' . $session['recording_url'];
    if (file_exists($file_path)) {
        unlink($file_path);
    }
    
    // Clear recording_url in database
    $stmt = $conn->prepare("UPDATE vle_live_sessions SET recording_url = NULL WHERE session_id = ?");
    $stmt->bind_param("i", $session_id);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Recording deleted successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to update database']);
    }
    exit;
}

// End a live session
if ($action === 'end_session' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $session_id = isset($_POST['session_id']) ? (int)$_POST['session_id'] : 0;
    
    // Verify lecturer owns this session
    $stmt = $conn->prepare("SELECT session_id FROM vle_live_sessions WHERE session_id = ? AND lecturer_id = ?");
    $stmt->bind_param("is", $session_id, $user['user_id']);
    $stmt->execute();
    
    if ($stmt->get_result()->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }
    
    // Update session status
    $stmt = $conn->prepare("UPDATE vle_live_sessions SET status = 'completed', ended_at = NOW() WHERE session_id = ?");
    $stmt->bind_param("i", $session_id);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Session ended']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Error ending session']);
    }
    exit;
}

// Get active sessions for a course
if ($action === 'get_sessions') {
    $course_id = isset($_GET['course_id']) ? (int)$_GET['course_id'] : 0;
    
    $stmt = $conn->prepare("
        SELECT * FROM vle_live_sessions 
        WHERE course_id = ? AND status = 'active'
        ORDER BY created_at DESC
    ");
    $stmt->bind_param("i", $course_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $sessions = [];
    while ($session = $result->fetch_assoc()) {
        $sessions[] = $session;
    }
    
    echo json_encode(['success' => true, 'sessions' => $sessions]);
    exit;
}

// Get session invites for student
if ($action === 'get_invites') {
    try {
        $stmt = $conn->prepare("
            SELECT vls.*, vcs.course_name, l.full_name as lecturer_name, 
                   COUNT(DISTINCT vsp.participant_id) as participant_count
            FROM vle_session_invites vsi
            JOIN vle_live_sessions vls ON vsi.session_id = vls.session_id
            JOIN vle_courses vcs ON vls.course_id = vcs.course_id
            JOIN users u ON vls.lecturer_id = u.user_id
            JOIN lecturers l ON u.related_lecturer_id = l.lecturer_id
            LEFT JOIN vle_session_participants vsp ON vls.session_id = vsp.session_id
            WHERE vsi.student_id = ? AND vls.status = 'active'
            GROUP BY vls.session_id
            ORDER BY vsi.invited_at DESC
        ");
        
        if (!$stmt) {
            echo json_encode(['success' => false, 'message' => 'Prepare failed']);
            exit;
        }
        
        $stmt->bind_param("s", $user['user_id']);
        
        if (!$stmt->execute()) {
            echo json_encode(['success' => false, 'message' => 'Execute failed: ' . $stmt->error]);
            exit;
        }
        
        $result = $stmt->get_result();
        
        $invites = [];
        while ($invite = $result->fetch_assoc()) {
            $invites[] = $invite;
        }
        
        echo json_encode(['success' => true, 'invites' => $invites]);
        exit;
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Exception: ' . $e->getMessage()]);
        exit;
    }
}

// Accept invite and join session
if ($action === 'accept_invite' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $session_id = isset($_POST['session_id']) ? (int)$_POST['session_id'] : 0;
        
        if (!$session_id) {
            echo json_encode(['success' => false, 'message' => 'Invalid session ID']);
            exit;
        }
        
        // Update invite status
        $stmt = $conn->prepare("
            UPDATE vle_session_invites 
            SET status = 'accepted', viewed_at = NOW()
            WHERE session_id = ? AND student_id = ?
        ");
        
        if (!$stmt) {
            echo json_encode(['success' => false, 'message' => 'Prepare failed']);
            exit;
        }
        
        $stmt->bind_param("is", $session_id, $user['user_id']);
        
        if (!$stmt->execute()) {
            echo json_encode(['success' => false, 'message' => 'Update invite failed']);
            exit;
        }
        
        // Update participant status and join time
        $stmt = $conn->prepare("
            UPDATE vle_session_participants 
            SET status = 'joined', joined_at = NOW()
            WHERE session_id = ? AND student_id = ?
        ");
        
        if (!$stmt) {
            echo json_encode(['success' => false, 'message' => 'Prepare failed']);
            exit;
        }
        
        $stmt->bind_param("is", $session_id, $user['user_id']);
        
        if (!$stmt->execute()) {
            echo json_encode(['success' => false, 'message' => 'Update participant failed']);
            exit;
        }
        
        // Get meeting URL
        $stmt = $conn->prepare("SELECT meeting_url FROM vle_live_sessions WHERE session_id = ?");
        
        if (!$stmt) {
            echo json_encode(['success' => false, 'message' => 'Prepare failed']);
            exit;
        }
        
        $stmt->bind_param("i", $session_id);
        
        if (!$stmt->execute()) {
            echo json_encode(['success' => false, 'message' => 'Query failed']);
            exit;
        }
        
        $result = $stmt->get_result();
        $session = $result->fetch_assoc();
        
        if (!$session) {
            echo json_encode(['success' => false, 'message' => 'Session not found']);
            exit;
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'Joined session',
            'meeting_url' => $session['meeting_url']
        ]);
        exit;
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Exception: ' . $e->getMessage()]);
        exit;
    }
}

// Mark as viewed (notify viewed)
if ($action === 'mark_viewed' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $session_id = isset($_POST['session_id']) ? (int)$_POST['session_id'] : 0;
    
    $stmt = $conn->prepare("
        UPDATE vle_session_invites 
        SET viewed_at = NOW()
        WHERE session_id = ? AND student_id = ? AND viewed_at IS NULL
    ");
    $stmt->bind_param("is", $session_id, $user['user_id']);
    $stmt->execute();
    
    echo json_encode(['success' => true]);
    exit;
}

// Get session participants
if ($action === 'get_participants') {
    try {
        $session_id = isset($_GET['session_id']) ? (int)$_GET['session_id'] : 0;
        
        if (!$session_id) {
            echo json_encode(['success' => false, 'message' => 'Invalid session ID']);
            exit;
        }
        
        $stmt = $conn->prepare("
            SELECT vsp.*, s.full_name, s.student_id
            FROM vle_session_participants vsp
            JOIN students s ON vsp.student_id = s.student_id
            WHERE vsp.session_id = ?
            ORDER BY vsp.status DESC, vsp.joined_at DESC
        ");
        
        if (!$stmt) {
            echo json_encode(['success' => false, 'message' => 'Prepare failed: ' . $conn->error]);
            exit;
        }
        
        $stmt->bind_param("i", $session_id);
        
        if (!$stmt->execute()) {
            echo json_encode(['success' => false, 'message' => 'Execute failed: ' . $stmt->error]);
            exit;
        }
        
        $result = $stmt->get_result();
        
        if (!$result) {
            echo json_encode(['success' => false, 'message' => 'Get result failed: ' . $stmt->error]);
            exit;
        }
        
        $participants = [];
        while ($participant = $result->fetch_assoc()) {
            $participants[] = $participant;
        }
        
        echo json_encode(['success' => true, 'participants' => $participants]);
        exit;
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Exception: ' . $e->getMessage()]);
        exit;
    }
}

// Search students to add as participants
if ($action === 'search_students') {
    $session_id = isset($_GET['session_id']) ? (int)$_GET['session_id'] : 0;
    $query = isset($_GET['q']) ? trim($_GET['q']) : '';
    
    if (!$session_id) {
        echo json_encode(['success' => false, 'message' => 'Invalid session ID']);
        exit;
    }
    
    // Verify lecturer owns this session
    $stmt = $conn->prepare("SELECT session_id, course_id FROM vle_live_sessions WHERE session_id = ? AND lecturer_id = ?");
    $stmt->bind_param("is", $session_id, $user['user_id']);
    $stmt->execute();
    $session = $stmt->get_result()->fetch_assoc();
    
    if (!$session) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }
    
    $course_id = $session['course_id'];
    $search = '%' . $conn->real_escape_string($query) . '%';
    
    // Search students enrolled in the course, flag those already added
    $sql = "SELECT s.student_id, s.full_name, s.email,
                   CASE WHEN vsp.participant_id IS NOT NULL THEN 1 ELSE 0 END as already_added
            FROM vle_enrollments ve
            JOIN students s ON ve.student_id = s.student_id
            LEFT JOIN vle_session_participants vsp ON vsp.session_id = $session_id AND vsp.student_id = s.student_id
            WHERE ve.course_id = $course_id
              AND (s.full_name LIKE '$search' OR s.student_id LIKE '$search' OR s.email LIKE '$search')
            ORDER BY already_added ASC, s.full_name ASC
            LIMIT 20";
    
    $result = $conn->query($sql);
    $students = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $students[] = $row;
        }
    }
    
    echo json_encode(['success' => true, 'students' => $students]);
    exit;
}

// Add a student as participant to a live session
if ($action === 'add_participant' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $session_id = isset($_POST['session_id']) ? (int)$_POST['session_id'] : 0;
    $student_id = isset($_POST['student_id']) ? trim($_POST['student_id']) : '';
    
    if (!$session_id || !$student_id) {
        echo json_encode(['success' => false, 'message' => 'Missing session ID or student ID']);
        exit;
    }
    
    // Verify lecturer owns this session
    $stmt = $conn->prepare("SELECT session_id, course_id, session_name FROM vle_live_sessions WHERE session_id = ? AND lecturer_id = ? AND status = 'active'");
    $stmt->bind_param("is", $session_id, $user['user_id']);
    $stmt->execute();
    $session = $stmt->get_result()->fetch_assoc();
    
    if (!$session) {
        echo json_encode(['success' => false, 'message' => 'Session not found, not active, or unauthorized']);
        exit;
    }
    
    // Check if already a participant
    $stmt = $conn->prepare("SELECT participant_id FROM vle_session_participants WHERE session_id = ? AND student_id = ?");
    $stmt->bind_param("is", $session_id, $student_id);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        echo json_encode(['success' => false, 'message' => 'Student is already a participant']);
        exit;
    }
    
    // Add participant
    $stmt = $conn->prepare("INSERT INTO vle_session_participants (session_id, student_id, status) VALUES (?, ?, 'invited')");
    $stmt->bind_param("is", $session_id, $student_id);
    @$stmt->execute();
    
    // Add invite
    $stmt = $conn->prepare("INSERT INTO vle_session_invites (session_id, student_id, status) VALUES (?, ?, 'pending')");
    $stmt->bind_param("is", $session_id, $student_id);
    @$stmt->execute();
    
    // Send email notification
    if (function_exists('isEmailEnabled') && isEmailEnabled()) {
        $si_stmt = $conn->prepare("SELECT full_name, email FROM students WHERE student_id = ?");
        $si_stmt->bind_param("s", $student_id);
        $si_stmt->execute();
        $student_info = $si_stmt->get_result()->fetch_assoc();
        $si_stmt->close();
        
        $course_info = $conn->query("SELECT c.course_name, l.full_name as lecturer_name FROM vle_courses c JOIN lecturers l ON c.lecturer_id = l.lecturer_id WHERE c.course_id = {$session['course_id']}")->fetch_assoc();
        
        if ($student_info && $student_info['email'] && $course_info) {
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $base_path = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/\\');
            $join_url = $protocol . '://' . $host . $base_path . '/student/live_room.php?session_id=' . $session_id;
            $invites_url = $protocol . '://' . $host . $base_path . '/student/live_invites.php';
            
            if (function_exists('sendLiveSessionInviteEmail')) {
                sendLiveSessionInviteEmail(
                    $student_info['email'],
                    $student_info['full_name'],
                    $course_info['lecturer_name'],
                    $course_info['course_name'],
                    $session['session_name'],
                    date('Y-m-d'),
                    date('H:i:s'),
                    $join_url,
                    '',
                    $invites_url
                );
            }
        }
    }
    
    // Create in-app notification
    if (function_exists('createNotification')) {
        $u_stmt = $conn->prepare("SELECT user_id FROM users WHERE related_student_id = ?");
        $u_stmt->bind_param("s", $student_id);
        $u_stmt->execute();
        $u_row = $u_stmt->get_result()->fetch_assoc();
        if ($u_row) {
            createNotification(
                $u_row['user_id'],
                'Live Session Invite',
                'You have been invited to join "' . $session['session_name'] . '". Join now!',
                'live_session',
                'student/live_invites.php'
            );
        }
    }
    
    echo json_encode(['success' => true, 'message' => 'Student added successfully']);
    exit;
}

// Remove a participant from a live session
if ($action === 'remove_participant' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $session_id = isset($_POST['session_id']) ? (int)$_POST['session_id'] : 0;
    $student_id = isset($_POST['student_id']) ? trim($_POST['student_id']) : '';
    
    if (!$session_id || !$student_id) {
        echo json_encode(['success' => false, 'message' => 'Missing required fields']);
        exit;
    }
    
    // Verify lecturer owns this session
    $stmt = $conn->prepare("SELECT session_id FROM vle_live_sessions WHERE session_id = ? AND lecturer_id = ?");
    $stmt->bind_param("is", $session_id, $user['user_id']);
    $stmt->execute();
    if ($stmt->get_result()->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }
    
    $stmt = $conn->prepare("DELETE FROM vle_session_participants WHERE session_id = ? AND student_id = ?");
    $stmt->bind_param("is", $session_id, $student_id);
    $stmt->execute();
    
    $stmt = $conn->prepare("DELETE FROM vle_session_invites WHERE session_id = ? AND student_id = ?");
    $stmt->bind_param("is", $session_id, $student_id);
    $stmt->execute();
    
    echo json_encode(['success' => true, 'message' => 'Participant removed']);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid action']);
?>
