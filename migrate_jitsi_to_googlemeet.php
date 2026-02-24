<?php
// migrate_jitsi_to_googlemeet.php - Migrate existing Jitsi meetings to Google Meet

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once 'includes/config.php';
require_once 'includes/auth.php';

// Check if user is logged in and is admin/staff
if (!isLoggedIn()) {
    die("You must be logged in to perform this action.");
}

$user = getCurrentUser();
if (!in_array($user['role'], ['staff', 'hod', 'dean'])) {
    die("You don't have permission to perform this action.");
}

$conn = getDbConnection();

// Function to generate Google Meet code
function generateGoogleMeetCode() {
    $part1 = strtolower(substr(bin2hex(random_bytes(2)), 0, 3));
    $part2 = strtolower(substr(bin2hex(random_bytes(3)), 0, 4));
    $part3 = strtolower(substr(bin2hex(random_bytes(3)), 0, 3));
    return $part1 . '-' . $part2 . '-' . $part3;
}

echo "<h2>Migrate Jitsi Meetings to Google Meet</h2>";

// Get all active Jitsi meetings
$result = $conn->query("SELECT session_id, meeting_url FROM vle_live_sessions WHERE meeting_url LIKE '%jitsi%' AND status = 'active'");

if ($result->num_rows === 0) {
    echo "<p>No active Jitsi meetings found.</p>";
} else {
    echo "<p>Found " . $result->num_rows . " active Jitsi meeting(s). Converting to Google Meet...</p>";
    echo "<ul>";
    
    while ($row = $result->fetch_assoc()) {
        $session_id = $row['session_id'];
        $old_url = $row['meeting_url'];
        
        // Generate new Google Meet URL
        $google_meet_code = generateGoogleMeetCode();
        $new_url = "https://meet.google.com/" . $google_meet_code;
        
        // Update the database
        $stmt = $conn->prepare("UPDATE vle_live_sessions SET meeting_url = ? WHERE session_id = ?");
        $stmt->bind_param("si", $new_url, $session_id);
        
        if ($stmt->execute()) {
            echo "<li>✓ Session " . $session_id . " updated<br>";
            echo "  Old: " . $old_url . "<br>";
            echo "  New: " . $new_url . "<br>";
            echo "  <a href='" . $new_url . "' target='_blank'>Join Meeting</a>";
            echo "</li>";
        } else {
            echo "<li>✗ Failed to update session " . $session_id . ": " . $conn->error . "</li>";
        }
    }
    
    echo "</ul>";
}

// Also migrate completed/past meetings if needed
$result = $conn->query("SELECT COUNT(*) as count FROM vle_live_sessions WHERE meeting_url LIKE '%jitsi%' AND status = 'completed'");
$row = $result->fetch_assoc();
if ($row['count'] > 0) {
    echo "<p>There are " . $row['count'] . " completed Jitsi meetings. <a href='?migrate_completed=1'>Click here to migrate them too</a>.</p>";
    
    if (isset($_GET['migrate_completed'])) {
        $result = $conn->query("SELECT session_id, meeting_url FROM vle_live_sessions WHERE meeting_url LIKE '%jitsi%' AND status = 'completed'");
        
        echo "<h3>Migrating Completed Meetings</h3>";
        echo "<ul>";
        
        while ($row = $result->fetch_assoc()) {
            $session_id = $row['session_id'];
            $old_url = $row['meeting_url'];
            
            $google_meet_code = generateGoogleMeetCode();
            $new_url = "https://meet.google.com/" . $google_meet_code;
            
            $stmt = $conn->prepare("UPDATE vle_live_sessions SET meeting_url = ? WHERE session_id = ?");
            $stmt->bind_param("si", $new_url, $session_id);
            
            if ($stmt->execute()) {
                echo "<li>✓ Completed session " . $session_id . " updated to: " . $new_url . "</li>";
            }
        }
        
        echo "</ul>";
    }
}

echo "<hr>";
echo "<p><a href='lecturer/live_classroom.php'>Back to Live Classroom</a></p>";

$conn->close();
?>
