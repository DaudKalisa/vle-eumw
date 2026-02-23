<?php
// api.php - API endpoints for VLE System
require_once '../includes/auth.php';
requireLogin();

header('Content-Type: application/json');
$conn = getDbConnection();

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$user = getCurrentUser();
$user_id = $user['user_id'];
$role = $user['role'];
$related_id = $_SESSION['vle_related_id'];

$response = ['success' => false, 'message' => 'Invalid action'];

try {
    switch ($action) {
        case 'mark_content_viewed':
            if ($role !== 'student') {
                throw new Exception('Unauthorized');
            }

            $content_id = (int)($_POST['content_id'] ?? 0);
            $enrollment_id = (int)($_POST['enrollment_id'] ?? 0);

            if (!$content_id || !$enrollment_id) {
                throw new Exception('Missing required parameters');
            }

            // Verify enrollment belongs to student
            $stmt = $conn->prepare("SELECT enrollment_id FROM vle_enrollments WHERE enrollment_id = ? AND student_id = ?");
            $stmt->bind_param("is", $enrollment_id, $related_id);
            $stmt->execute();
            if ($stmt->get_result()->num_rows === 0) {
                throw new Exception('Invalid enrollment');
            }
            $stmt->close();

            // Check if already marked
            $stmt = $conn->prepare("SELECT progress_id FROM vle_progress WHERE enrollment_id = ? AND content_id = ? AND progress_type = 'content_viewed'");
            $stmt->bind_param("ii", $enrollment_id, $content_id);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) {
                $response = ['success' => true, 'message' => 'Already marked as viewed'];
                break;
            }
            $stmt->close();

            // Mark as viewed
            $stmt = $conn->prepare("INSERT INTO vle_progress (enrollment_id, content_id, progress_type) VALUES (?, ?, 'content_viewed')");
            $stmt->bind_param("ii", $enrollment_id, $content_id);
            $stmt->execute();
            $stmt->close();

            $response = ['success' => true, 'message' => 'Content marked as viewed'];
            break;

        case 'submit_assignment':
            if ($role !== 'student') {
                throw new Exception('Unauthorized');
            }

            $assignment_id = (int)($_POST['assignment_id'] ?? 0);
            $enrollment_id = (int)($_POST['enrollment_id'] ?? 0);
            $text_content = $_POST['text_content'] ?? '';
            $file_path = $_POST['file_path'] ?? null;

            if (!$assignment_id || !$enrollment_id) {
                throw new Exception('Missing required parameters');
            }

            // Verify enrollment belongs to student
            $stmt = $conn->prepare("SELECT enrollment_id FROM vle_enrollments WHERE enrollment_id = ? AND student_id = ?");
            $stmt->bind_param("is", $enrollment_id, $related_id);
            $stmt->execute();
            if ($stmt->get_result()->num_rows === 0) {
                throw new Exception('Invalid enrollment');
            }
            $stmt->close();

            // Check if already submitted
            $stmt = $conn->prepare("SELECT submission_id FROM vle_submissions WHERE assignment_id = ? AND student_id = ?");
            $stmt->bind_param("is", $assignment_id, $related_id);
            $stmt->execute();
            $existing = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($existing) {
                // Update existing submission
                $stmt = $conn->prepare("UPDATE vle_submissions SET submission_date = NOW(), text_content = ?, file_path = ?, status = 'submitted' WHERE submission_id = ?");
                $stmt->bind_param("ssi", $text_content, $file_path, $existing['submission_id']);
                $stmt->execute();
                $stmt->close();
            } else {
                // New submission
                $stmt = $conn->prepare("INSERT INTO vle_submissions (assignment_id, student_id, text_content, file_path) VALUES (?, ?, ?, ?)");
                $stmt->bind_param("isss", $assignment_id, $related_id, $text_content, $file_path);
                $stmt->execute();
                $stmt->close();
            }

            $response = ['success' => true, 'message' => 'Assignment submitted successfully'];
            break;

        case 'get_course_content':
            $course_id = (int)($_GET['course_id'] ?? 0);
            $week = (int)($_GET['week'] ?? 0);

            if (!$course_id) {
                throw new Exception('Missing course_id');
            }

            // Verify access (student enrolled or lecturer owns course)
            $can_access = false;
            if ($role === 'student') {
                $stmt = $conn->prepare("SELECT enrollment_id FROM vle_enrollments WHERE course_id = ? AND student_id = ?");
                $stmt->bind_param("is", $course_id, $related_id);
                $stmt->execute();
                $can_access = $stmt->get_result()->num_rows > 0;
                $stmt->close();
            } elseif ($role === 'lecturer') {
                $stmt = $conn->prepare("SELECT course_id FROM vle_courses WHERE course_id = ? AND lecturer_id = ?");
                $stmt->bind_param("is", $course_id, $related_id);
                $stmt->execute();
                $can_access = $stmt->get_result()->num_rows > 0;
                $stmt->close();
            }

            if (!$can_access) {
                throw new Exception('Access denied');
            }

            $content = [];
            $query = "SELECT * FROM vle_weekly_content WHERE course_id = ?";
            $params = [$course_id];
            $types = "i";

            if ($week > 0) {
                $query .= " AND week_number = ?";
                $params[] = $week;
                $types .= "i";
            }

            $query .= " ORDER BY week_number, sort_order";

            $stmt = $conn->prepare($query);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();

            while ($row = $result->fetch_assoc()) {
                $content[] = $row;
            }
            $stmt->close();

            $response = ['success' => true, 'content' => $content];
            break;

        case 'get_assignments':
            $course_id = (int)($_GET['course_id'] ?? 0);
            $week = (int)($_GET['week'] ?? 0);

            if (!$course_id) {
                throw new Exception('Missing course_id');
            }

            // Verify access
            $can_access = false;
            if ($role === 'student') {
                $stmt = $conn->prepare("SELECT enrollment_id FROM vle_enrollments WHERE course_id = ? AND student_id = ?");
                $stmt->bind_param("is", $course_id, $related_id);
                $stmt->execute();
                $can_access = $stmt->get_result()->num_rows > 0;
                $stmt->close();
            } elseif ($role === 'lecturer') {
                $stmt = $conn->prepare("SELECT course_id FROM vle_courses WHERE course_id = ? AND lecturer_id = ?");
                $stmt->bind_param("is", $course_id, $related_id);
                $stmt->execute();
                $can_access = $stmt->get_result()->num_rows > 0;
                $stmt->close();
            }

            if (!$can_access) {
                throw new Exception('Access denied');
            }

            $assignments = [];
            $query = "SELECT * FROM vle_assignments WHERE course_id = ? AND is_active = TRUE";
            $params = [$course_id];
            $types = "i";

            if ($week > 0) {
                $query .= " AND week_number = ?";
                $params[] = $week;
                $types .= "i";
            }

            $query .= " ORDER BY week_number, assignment_type";

            $stmt = $conn->prepare($query);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();

            while ($row = $result->fetch_assoc()) {
                $assignments[] = $row;
            }
            $stmt->close();

            $response = ['success' => true, 'assignments' => $assignments];
            break;

        default:
            $response = ['success' => false, 'message' => 'Unknown action'];
    }

} catch (Exception $e) {
    $response = ['success' => false, 'message' => $e->getMessage()];
}

$conn->close();
echo json_encode($response);
?>