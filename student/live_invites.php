<?php
// student/live_invites.php - Student live session invites and classroom joining
require_once '../includes/auth.php';
requireLogin();
requireRole(['student']);

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

$user = getCurrentUser();
$student_id = $user['user_id'];

// Get pending invites
$pending_invites = [];
$result = $conn->query("
    SELECT vls.*, vcs.course_name, l.full_name as lecturer_name,
           COUNT(DISTINCT CASE WHEN vsp.status = 'joined' THEN vsp.participant_id END) as active_participants,
           COUNT(DISTINCT vsp.participant_id) as total_participants,
           vsi.status as invite_status, vsi.invited_at as sent_at, vsi.viewed_at, vsi.viewed_at as accepted_at
    FROM vle_session_invites vsi
    JOIN vle_live_sessions vls ON vsi.session_id = vls.session_id
    JOIN vle_courses vcs ON vls.course_id = vcs.course_id
    JOIN users u ON vls.lecturer_id = u.user_id
    JOIN lecturers l ON u.related_lecturer_id = l.lecturer_id
    LEFT JOIN vle_session_participants vsp ON vls.session_id = vsp.session_id
    WHERE vsi.student_id = '$student_id' AND vls.status = 'active'
    GROUP BY vls.session_id
    ORDER BY vsi.invited_at DESC
");

while ($row = $result->fetch_assoc()) {
    $pending_invites[] = $row;
}

// Ensure recording_url column exists
$conn->query("ALTER TABLE vle_live_sessions ADD COLUMN IF NOT EXISTS recording_url VARCHAR(500) DEFAULT NULL");

// Get ALL active live sessions from courses the student is enrolled in
$active_sessions = [];
$result = $conn->query("
    SELECT vls.*, vcs.course_name, l.full_name as lecturer_name,
           COUNT(DISTINCT CASE WHEN vsp.status = 'joined' THEN vsp.participant_id END) as active_participants,
           COUNT(DISTINCT vsp.participant_id) as total_participants,
           vls.started_at
    FROM vle_live_sessions vls
    JOIN vle_courses vcs ON vls.course_id = vcs.course_id
    JOIN users u ON vls.lecturer_id = u.user_id
    JOIN lecturers l ON u.related_lecturer_id = l.lecturer_id
    LEFT JOIN vle_session_participants vsp ON vls.session_id = vsp.session_id
    WHERE vls.status = 'active'
      AND vls.course_id IN (
          SELECT course_id FROM vle_enrollments WHERE student_id = '$student_id'
      )
    GROUP BY vls.session_id
    ORDER BY vls.started_at DESC
");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $active_sessions[] = $row;
    }
}

// Get past sessions student joined
$past_sessions = [];
$result = $conn->query("
    SELECT vls.*, vcs.course_name, l.full_name as lecturer_name,
           vsp.joined_at, vsp.left_at, vsp.status as participant_status
    FROM vle_session_participants vsp
    JOIN vle_live_sessions vls ON vsp.session_id = vls.session_id
    JOIN vle_courses vcs ON vls.course_id = vcs.course_id
    JOIN users u ON vls.lecturer_id = u.user_id
    JOIN lecturers l ON u.related_lecturer_id = l.lecturer_id
    WHERE vsp.student_id = '$student_id' AND vls.status = 'completed'
    ORDER BY vls.ended_at DESC
    LIMIT 10
");

while ($row = $result->fetch_assoc()) {
    $past_sessions[] = $row;
}

// Get recorded sessions the student missed (invited but never joined)
$missed_recordings = [];
$result = $conn->query("
    SELECT vls.*, vcs.course_name, l.full_name as lecturer_name,
           vsi.invited_at
    FROM vle_session_invites vsi
    JOIN vle_live_sessions vls ON vsi.session_id = vls.session_id
    JOIN vle_courses vcs ON vls.course_id = vcs.course_id
    JOIN users u ON vls.lecturer_id = u.user_id
    JOIN lecturers l ON u.related_lecturer_id = l.lecturer_id
    WHERE vsi.student_id = '$student_id'
      AND vls.status = 'completed'
      AND vls.recording_url IS NOT NULL
      AND vls.recording_url != ''
      AND vsi.session_id NOT IN (
          SELECT session_id FROM vle_session_participants WHERE student_id = '$student_id'
      )
    ORDER BY vls.ended_at DESC
    LIMIT 10
");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $missed_recordings[] = $row;
    }
}

// Get ALL recordings from courses the student is enrolled in (broadest access)
$all_course_recordings = [];
$result = $conn->query("
    SELECT vls.*, vcs.course_name, l.full_name as lecturer_name,
           CASE
               WHEN vsp.participant_id IS NOT NULL THEN 'attended'
               WHEN vsi.invite_id IS NOT NULL THEN 'missed'
               ELSE 'course'
           END as access_type
    FROM vle_live_sessions vls
    JOIN vle_courses vcs ON vls.course_id = vcs.course_id
    JOIN users u ON vls.lecturer_id = u.user_id
    JOIN lecturers l ON u.related_lecturer_id = l.lecturer_id
    LEFT JOIN vle_session_participants vsp ON vls.session_id = vsp.session_id AND vsp.student_id = '$student_id'
    LEFT JOIN vle_session_invites vsi ON vls.session_id = vsi.session_id AND vsi.student_id = '$student_id'
    WHERE vls.status = 'completed'
      AND vls.recording_url IS NOT NULL
      AND vls.recording_url != ''
      AND vls.course_id IN (
          SELECT course_id FROM vle_enrollments WHERE student_id = '$student_id'
      )
    ORDER BY vls.ended_at DESC
    LIMIT 20
");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $all_course_recordings[] = $row;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Live Invites - VLE System</title>
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
        .invite-card {
            border: 2px solid #f0f0f0;
            border-radius: var(--vle-radius-lg);
            transition: all 0.3s ease;
            position: relative;
        }
        .invite-card.new {
            border-color: var(--vle-accent);
            background: linear-gradient(135deg, rgba(102,126,234,0.05) 0%, rgba(118,75,162,0.05) 100%);
        }
        .invite-card:hover {
            box-shadow: 0 8px 24px rgba(0,0,0,0.12);
            border-color: var(--vle-accent);
        }
        .invite-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 15px;
        }
        .session-topic {
            font-size: 18px;
            font-weight: 600;
            color: var(--vle-primary);
            margin-bottom: 5px;
        }
        .lecturer-info {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 12px;
        }
        .lecturer-avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: var(--vle-accent);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 14px;
        }
        .invite-details {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-bottom: 15px;
            font-size: 13px;
        }
        .detail-item {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #666;
        }
        .participant-count {
            background: var(--vle-accent);
            color: white;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
            display: inline-block;
        }
        .action-buttons {
            display: flex;
            gap: 8px;
            margin-top: 15px;
        }
        .action-buttons button {
            flex: 1;
        }
        .action-buttons .btn-success {
            flex: 2;
            font-weight: 600;
            font-size: 15px;
            padding: 10px 16px;
            border-radius: 8px;
            animation: pulse 1.5s infinite;
        }
        .active-session-banner {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            border-radius: var(--vle-radius-lg);
            padding: 20px;
            color: #fff;
            margin-bottom: 24px;
        }
        .active-session-banner .session-item {
            background: rgba(255,255,255,0.15);
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 12px;
        }
        .active-session-banner .session-item:last-child { margin-bottom: 0; }
        .btn-join-active {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: #fff;
            color: #28a745;
            font-weight: 700;
            font-size: 15px;
            padding: 12px 28px;
            border-radius: 10px;
            border: none;
            text-decoration: none;
            transition: all 0.2s;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        .btn-join-active:hover {
            background: #f0fff4;
            color: #1a7a34;
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(0,0,0,0.2);
        }
        .past-session-card {
            border: 1px solid #dee2e6;
            border-radius: var(--vle-radius-md);
            padding: 15px;
            background: #f8f9fa;
        }
        .status-badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: bold;
        }
        .status-badge.new {
            background: #ffc107;
            color: #000;
        }
        .status-badge.viewed {
            background: #17a2b8;
            color: white;
        }
        .status-badge.joined {
            background: #28a745;
            color: white;
        }
        .status-badge.recorded {
            background: #e74c3c;
            color: white;
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
        .missed-card {
            border: 1px solid #dee2e6;
            border-radius: var(--vle-radius-md);
            padding: 15px;
            background: #fff;
            border-left: 4px solid #e74c3c;
        }
        /* Recording modal player */
        .recording-player video {
            width: 100%;
            border-radius: 8px;
            background: #000;
        }
    </style>
</head>
<body>
    <?php 
    $currentPage = 'live_invites';
    $pageTitle = 'Live Class Invites';
    include 'header_nav.php'; 
    ?>

    <div class="vle-content">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2 class="vle-page-title"><i class="bi bi-camera-video me-2"></i>Live Classroom Invites</h2>
            <div class="d-flex gap-2">
                <a href="#recordedSection" class="btn btn-danger">
                    <i class="bi bi-collection-play me-1"></i>Recorded Sessions
                    <?php 
                    $rec_count = count($all_course_recordings);
                    if ($rec_count > 0): ?>
                        <span class="badge bg-light text-danger ms-1"><?php echo $rec_count; ?></span>
                    <?php endif; ?>
                </a>
                <a href="dashboard.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Back</a>
            </div>
        </div>

        <!-- Active Live Classrooms Banner -->
        <?php if (!empty($active_sessions)): ?>
        <div class="active-session-banner">
            <h4 class="mb-3">
                <i class="bi bi-broadcast me-2"></i>Active Live Classrooms
                <span class="badge bg-light text-success ms-2"><?php echo count($active_sessions); ?> LIVE NOW</span>
            </h4>
            <?php foreach ($active_sessions as $as): ?>
            <div class="session-item d-flex flex-wrap justify-content-between align-items-center">
                <div class="mb-2 mb-md-0">
                    <strong class="d-block" style="font-size:16px;"><?php echo htmlspecialchars($as['session_name']); ?></strong>
                    <small>
                        <i class="bi bi-book me-1"></i><?php echo htmlspecialchars($as['course_name']); ?> &bull; 
                        <i class="bi bi-person me-1"></i><?php echo htmlspecialchars($as['lecturer_name']); ?> &bull; 
                        <i class="bi bi-people me-1"></i><?php echo $as['active_participants']; ?> online &bull; 
                        <i class="bi bi-clock me-1"></i>Started <?php echo date('H:i', strtotime($as['started_at'])); ?>
                    </small>
                </div>
                <a href="live_room.php?session_id=<?php echo $as['session_id']; ?>" 
                   class="btn-join-active"
                   onclick="markInviteAccepted(<?php echo $as['session_id']; ?>)">
                    <i class="bi bi-camera-video-fill"></i> Join Active Classroom
                </a>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Pending Invites Section -->
        <div class="mb-5">
            <h4 class="mb-3">
                <i class="bi bi-bell me-2"></i>Active Live Sessions
                <?php if (!empty($pending_invites)): ?>
                    <span class="live-badge ms-2">
                        <i class="bi bi-circle-fill me-1"></i><?php echo count($pending_invites); ?> ACTIVE
                    </span>
                <?php endif; ?>
            </h4>

            <?php if (empty($pending_invites)): ?>
                <div class="alert alert-info">
                    <i class="bi bi-info-circle me-2"></i>No active live sessions at the moment. Check back later or wait for your lecturer to start a session.
                </div>
            <?php else: ?>
                <div class="row">
                    <?php foreach ($pending_invites as $invite): ?>
                        <div class="col-md-6 mb-3">
                            <div class="card invite-card <?php echo $invite['invite_status'] === 'sent' ? 'new' : ''; ?>">
                                <div class="card-body">
                                    <!-- Header -->
                                    <div class="invite-header">
                                        <div>
                                            <div class="session-topic"><?php echo htmlspecialchars($invite['session_name']); ?></div>
                                            <small class="text-muted"><i class="bi bi-book me-1"></i><?php echo htmlspecialchars($invite['course_name']); ?></small>
                                        </div>
                                        <span class="live-badge">
                                            <i class="bi bi-circle-fill me-1"></i>LIVE
                                        </span>
                                    </div>

                                    <!-- Lecturer Info -->
                                    <div class="lecturer-info">
                                        <div class="lecturer-avatar"><?php echo strtoupper(substr($invite['lecturer_name'], 0, 1)); ?></div>
                                        <div>
                                            <small class="d-block"><strong><?php echo htmlspecialchars($invite['lecturer_name']); ?></strong></small>
                                            <small class="text-muted">Lecturer</small>
                                        </div>
                                    </div>

                                    <!-- Details Grid -->
                                    <div class="invite-details">
                                        <div class="detail-item">
                                            <i class="bi bi-people text-primary"></i>
                                            <span><?php echo $invite['active_participants']; ?> currently online</span>
                                        </div>
                                        <div class="detail-item">
                                            <i class="bi bi-hourglass-split text-warning"></i>
                                            <span>Started <?php echo date('H:i', strtotime($invite['sent_at'])); ?></span>
                                        </div>
                                        <div class="detail-item">
                                            <i class="bi bi-share text-info"></i>
                                            <span class="status-badge <?php echo $invite['invite_status']; ?>"><?php echo ucfirst($invite['invite_status']); ?></span>
                                        </div>
                                        <div class="detail-item">
                                            <i class="bi bi-person-check text-success"></i>
                                            <span class="participant-count"><?php echo $invite['total_participants']; ?> invited</span>
                                        </div>
                                    </div>

                                    <!-- Action Buttons -->
                                    <div class="action-buttons">
                                        <a href="live_room.php?session_id=<?php echo $invite['session_id']; ?>" 
                                           class="btn btn-success" 
                                           onclick="markInviteAccepted(<?php echo $invite['session_id']; ?>)">
                                            <i class="bi bi-camera-video-fill me-1"></i>Join Live Session
                                        </a>
                                        <button type="button" class="btn btn-outline-info btn-sm" 
                                                onclick="viewDetails(<?php echo $invite['session_id']; ?>)">
                                            <i class="bi bi-info-circle me-1"></i>Details
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Past Sessions Section -->
        <?php if (!empty($past_sessions)): ?>
        <div class="mb-5">
            <h4 class="mb-3"><i class="bi bi-clock-history me-2"></i>Past Sessions</h4>
            <div class="row">
                <?php foreach ($past_sessions as $session): ?>
                    <div class="col-md-6 mb-3">
                        <div class="past-session-card">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <h6 class="mb-0"><?php echo htmlspecialchars($session['session_name']); ?></h6>
                                <?php if (!empty($session['recording_url'])): ?>
                                    <span class="recorded-badge"><i class="bi bi-record-circle-fill"></i> Recorded</span>
                                <?php endif; ?>
                            </div>
                            <p class="text-muted small mb-2">
                                <i class="bi bi-book me-1"></i><?php echo htmlspecialchars($session['course_name']); ?> | 
                                <i class="bi bi-person me-1"></i><?php echo htmlspecialchars($session['lecturer_name']); ?>
                            </p>
                            <p class="text-muted small mb-2">
                                <i class="bi bi-clock me-1"></i>Attended: <?php echo date('M d, Y H:i', strtotime($session['joined_at'])); ?> to <?php echo ($session['left_at'] ? date('H:i', strtotime($session['left_at'])) : 'Still in session'); ?>
                            </p>
                            <div class="d-flex align-items-center gap-2">
                                <span class="status-badge joined">
                                    <i class="bi bi-check-circle me-1"></i>Completed
                                </span>
                                <?php if (!empty($session['recording_url'])): ?>
                                    <button class="btn btn-sm btn-outline-danger" onclick="watchRecording('<?php echo htmlspecialchars($session['recording_url']); ?>', '<?php echo htmlspecialchars(addslashes($session['session_name'])); ?>')">
                                        <i class="bi bi-play-circle me-1"></i>Watch Recording
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- All Recorded Sessions Section -->
        <div class="mb-5" id="recordedSection">
            <h4 class="mb-3">
                <i class="bi bi-collection-play me-2"></i>All Recorded Sessions
                <?php if ($rec_count > 0): ?>
                    <span class="recorded-badge ms-2"><?php echo $rec_count; ?></span>
                <?php endif; ?>
            </h4>

            <?php 
            // Use the broader all_course_recordings query
            $all_recordings = $all_course_recordings;
            ?>

            <?php if (empty($all_recordings)): ?>
                <div class="alert alert-secondary">
                    <i class="bi bi-info-circle me-2"></i>No recorded sessions available yet. When your lecturer records a live session, recordings will appear here.
                </div>
            <?php else: ?>
                <div class="row">
                    <?php foreach ($all_recordings as $rec): ?>
                        <div class="col-md-6 mb-3">
                            <div class="<?php echo ($rec['access_type'] ?? 'course') !== 'attended' ? 'missed-card' : 'past-session-card'; ?>">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <h6 class="mb-0"><?php echo htmlspecialchars($rec['session_name']); ?></h6>
                                    <div class="d-flex gap-1">
                                        <span class="recorded-badge"><i class="bi bi-record-circle-fill"></i> Recorded</span>
                                        <?php if (($rec['access_type'] ?? '') === 'missed'): ?>
                                            <span class="status-badge" style="background:#ffc107;color:#000;">Missed</span>
                                        <?php elseif (($rec['access_type'] ?? '') === 'attended'): ?>
                                            <span class="status-badge joined"><i class="bi bi-check-circle"></i> Attended</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <p class="text-muted small mb-2">
                                    <i class="bi bi-book me-1"></i><?php echo htmlspecialchars($rec['course_name']); ?> | 
                                    <i class="bi bi-person me-1"></i><?php echo htmlspecialchars($rec['lecturer_name']); ?>
                                </p>
                                <p class="text-muted small mb-2">
                                    <i class="bi bi-calendar me-1"></i>Session Date: <?php echo date('M d, Y H:i', strtotime($rec['ended_at'] ?? $rec['created_at'])); ?>
                                </p>
                                <button class="btn btn-sm btn-danger" onclick="watchRecording('<?php echo htmlspecialchars($rec['recording_url']); ?>', '<?php echo htmlspecialchars(addslashes($rec['session_name'])); ?>')">
                                    <i class="bi bi-play-circle me-1"></i>Watch Recording
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Missed Recorded Sessions Section -->
        <?php if (!empty($missed_recordings)): ?>
        <div class="mb-5">
            <h4 class="mb-3">
                <i class="bi bi-collection-play me-2"></i>Recorded Sessions You Missed
                <span class="recorded-badge ms-2"><?php echo count($missed_recordings); ?></span>
            </h4>
            <div class="row">
                <?php foreach ($missed_recordings as $rec): ?>
                    <div class="col-md-6 mb-3">
                        <div class="missed-card">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <h6 class="mb-0"><?php echo htmlspecialchars($rec['session_name']); ?></h6>
                                <span class="recorded-badge"><i class="bi bi-record-circle-fill"></i> Recorded</span>
                            </div>
                            <p class="text-muted small mb-2">
                                <i class="bi bi-book me-1"></i><?php echo htmlspecialchars($rec['course_name']); ?> | 
                                <i class="bi bi-person me-1"></i><?php echo htmlspecialchars($rec['lecturer_name']); ?>
                            </p>
                            <p class="text-muted small mb-2">
                                <i class="bi bi-calendar me-1"></i>Session Date: <?php echo date('M d, Y H:i', strtotime($rec['ended_at'] ?? $rec['created_at'])); ?>
                            </p>
                            <button class="btn btn-sm btn-danger" onclick="watchRecording('<?php echo htmlspecialchars($rec['recording_url']); ?>', '<?php echo htmlspecialchars(addslashes($rec['session_name'])); ?>')">
                                <i class="bi bi-play-circle me-1"></i>Watch Recording
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>

    <!-- Recording Player Modal -->
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

    <script>
        // Mark invite as accepted in background (non-blocking, link handles navigation)
        function markInviteAccepted(sessionId) {
            // Fire and forget — mark viewed + accept invite
            fetch('../api/live_session_api.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: `action=mark_viewed&session_id=${sessionId}`
            }).catch(() => {});

            fetch('../api/live_session_api.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: `action=accept_invite&session_id=${sessionId}`
            }).catch(() => {});
        }

        // Legacy joinSession (kept for compatibility)
        async function joinSession(sessionId) {
            try {
                // Mark as viewed
                await fetch('../api/live_session_api.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: `action=mark_viewed&session_id=${sessionId}`
                });

                // Accept invite
                const response = await fetch('../api/live_session_api.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: `action=accept_invite&session_id=${sessionId}`
                });

                const data = await response.json();

                if (data.success) {
                    window.location.href = 'live_room.php?session_id=' + sessionId;
                } else {
                    alert('Error: ' + data.message);
                }
            } catch (error) {
                alert('Error joining session: ' + error.message);
            }
        }

        // View session details
        function viewDetails(sessionId) {
            alert('Session Details\n\nSession ID: ' + sessionId);
        }

        // Poll for new invites (optional - check for new sessions every 30 seconds)
        setInterval(() => {
            // Silently refresh pending invites in background
        }, 30000);

        // Watch a recording in modal player
        function watchRecording(url, title) {
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

            // Pause video when modal is closed
            document.getElementById('recordingModal').addEventListener('hidden.bs.modal', function () {
                video.pause();
                video.src = '';
            }, { once: true });
        }
    </script>
</body>
</html>
