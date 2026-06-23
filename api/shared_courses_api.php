<?php
// api/shared_courses_api.php - REST API endpoints for shared course management
header('Content-Type: application/json');

require_once '../includes/auth.php';
requireLogin();

$conn = getDbConnection();
$method = $_SERVER['REQUEST_METHOD'];
$action = isset($_GET['action']) ? trim($_GET['action']) : '';

// Helper function to send JSON response
function sendResponse($success, $data = null, $message = '') {
    echo json_encode([
        'success' => $success,
        'data' => $data,
        'message' => $message
    ]);
    exit;
}

try {
    // GET: Retrieve shared courses for a student's program
    if ($method === 'GET' && $action === 'get_shared_for_program') {
        $program_id = isset($_GET['program_id']) ? (int)$_GET['program_id'] : 0;
        
        if ($program_id <= 0) {
            sendResponse(false, null, 'Invalid program ID');
        }
        
        $courses = [];
        $result = $conn->query("
            SELECT DISTINCT c.course_id, c.course_code, c.course_name, c.description,
                   c.program_of_study, c.year_of_study, c.semester, c.lecturer_id,
                   l.full_name as lecturer_name
            FROM vle_courses c
            LEFT JOIN lecturers l ON c.lecturer_id = l.lecturer_id
            INNER JOIN course_programs cp ON c.course_id = cp.course_id
            WHERE cp.program_id = $program_id AND c.is_shared = 1 AND c.is_active = 1
            ORDER BY c.course_code
        ");
        
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $courses[] = $row;
            }
        }
        
        sendResponse(true, $courses, 'Shared courses retrieved successfully');
    }
    
    // GET: Get program connection details for a course
    elseif ($method === 'GET' && $action === 'get_course_connections') {
        $course_id = isset($_GET['course_id']) ? (int)$_GET['course_id'] : 0;
        
        if ($course_id <= 0) {
            sendResponse(false, null, 'Invalid course ID');
        }
        
        $connections = [];
        $result = $conn->query("
            SELECT p.program_id, p.program_code, p.program_name, p.program_type,
                   COUNT(DISTINCT s.student_id) as student_count
            FROM programs p
            LEFT JOIN students s ON s.program = p.program_name
            WHERE p.program_id IN (
                SELECT program_id FROM course_programs WHERE course_id = $course_id
            )
            GROUP BY p.program_id
            ORDER BY p.program_name
        ");
        
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $connections[] = $row;
            }
        }
        
        sendResponse(true, $connections, 'Course connections retrieved');
    }
    
    // GET: Get student enrollment across shared courses
    elseif ($method === 'GET' && $action === 'get_shared_enrollment_stats') {
        $stats = [];
        
        // Total shared courses
        $result = $conn->query("SELECT COUNT(*) as count FROM vle_courses WHERE is_shared = 1");
        $row = $result->fetch_assoc();
        $stats['total_shared_courses'] = $row['count'];
        
        // Program coverage
        $result = $conn->query("
            SELECT COUNT(DISTINCT program_id) as count FROM course_programs
        ");
        $row = $result->fetch_assoc();
        $stats['programs_sharing'] = $row['count'];
        
        // Cross-program enrollment in shared courses
        $result = $conn->query("
            SELECT COUNT(DISTINCT ve.student_id) as count
            FROM vle_enrollments ve
            INNER JOIN vle_courses c ON ve.course_id = c.course_id
            WHERE c.is_shared = 1
        ");
        $row = $result->fetch_assoc();
        $stats['cross_program_students'] = $row['count'];
        
        sendResponse(true, $stats, 'Enrollment statistics retrieved');
    }
    
    // GET: Get sharing matrix data
    elseif ($method === 'GET' && $action === 'get_sharing_matrix') {
        $matrix = [];
        
        $result = $conn->query("
            SELECT p.program_id, p.program_name, p.program_code,
                   COUNT(DISTINCT cp.course_id) as shared_course_count,
                   GROUP_CONCAT(DISTINCT c.course_code SEPARATOR ', ') as course_codes
            FROM programs p
            LEFT JOIN course_programs cp ON p.program_id = cp.program_id
            LEFT JOIN vle_courses c ON cp.course_id = c.course_id
            WHERE p.is_active = 1
            GROUP BY p.program_id
            ORDER BY shared_course_count DESC, p.program_name
        ");
        
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                if ((int)$row['shared_course_count'] > 0) {
                    $matrix[] = $row;
                }
            }
        }
        
        sendResponse(true, $matrix, 'Sharing matrix retrieved');
    }
    
    // POST: Bulk share course with multiple programs
    elseif ($method === 'POST' && $action === 'bulk_share_course') {
        requireRole(['staff', 'admin']);
        
        $course_id = isset($_POST['course_id']) ? (int)$_POST['course_id'] : 0;
        $program_ids = isset($_POST['program_ids']) ? (array)$_POST['program_ids'] : [];
        $program_ids = array_map('intval', array_filter($program_ids));
        
        if ($course_id <= 0 || empty($program_ids)) {
            sendResponse(false, null, 'Invalid course ID or programs');
        }
        
        // Clear existing associations
        $conn->query("DELETE FROM course_programs WHERE course_id = $course_id");
        
        // Insert new associations
        $inserted = 0;
        $stmt = $conn->prepare("INSERT INTO course_programs (course_id, program_id) VALUES (?, ?)");
        
        foreach ($program_ids as $prog_id) {
            $stmt->bind_param("ii", $course_id, $prog_id);
            if ($stmt->execute()) {
                $inserted++;
            }
        }
        $stmt->close();
        
        // Mark course as shared
        $conn->query("UPDATE vle_courses SET is_shared = 1 WHERE course_id = $course_id");
        
        sendResponse(true, ['inserted' => $inserted], "Course shared with $inserted program(s)");
    }
    
    // POST: Unshare course
    elseif ($method === 'POST' && $action === 'unshare_course') {
        requireRole(['staff', 'admin']);
        
        $course_id = isset($_POST['course_id']) ? (int)$_POST['course_id'] : 0;
        
        if ($course_id <= 0) {
            sendResponse(false, null, 'Invalid course ID');
        }
        
        $conn->query("DELETE FROM course_programs WHERE course_id = $course_id");
        $conn->query("UPDATE vle_courses SET is_shared = 0 WHERE course_id = $course_id");
        
        sendResponse(true, null, 'Course unshared successfully');
    }
    
    else {
        sendResponse(false, null, 'Invalid action');
    }
    
} catch (Exception $e) {
    sendResponse(false, null, 'Error: ' . $e->getMessage());
}
?>
