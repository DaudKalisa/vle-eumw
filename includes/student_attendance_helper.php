<?php
/**
 * Student Attendance Auto-Tracking Helper
 * Records attendance automatically when students access courses, content, or live sessions.
 * Only records for students who have paid tuition fees (payment_percentage > 0).
 */

if (!defined('STUDENT_ATTENDANCE_HELPER_LOADED')) {
    define('STUDENT_ATTENDANCE_HELPER_LOADED', true);
}

/**
 * Check if student has paid tuition (any payment recorded)
 * @param mysqli $conn
 * @param string $student_id
 * @return array ['has_paid' => bool, 'payment_percentage' => int, 'content_access_weeks' => int]
 */
function getStudentPaymentStatus($conn, $student_id) {
    $result = ['has_paid' => false, 'payment_percentage' => 0, 'content_access_weeks' => 0];
    $stmt = $conn->prepare("SELECT payment_percentage, content_access_weeks, total_paid FROM student_finances WHERE student_id = ?");
    if ($stmt) {
        $stmt->bind_param("s", $student_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            $result['payment_percentage'] = (int)($row['payment_percentage'] ?? 0);
            $result['content_access_weeks'] = (int)($row['content_access_weeks'] ?? 0);
            $result['has_paid'] = ((float)($row['total_paid'] ?? 0)) > 0;
        }
    }
    return $result;
}

/**
 * Auto-record attendance when student accesses a course page / content / live session.
 * Only one record per student per course per day. Only for students who have paid tuition.
 *
 * @param mysqli $conn
 * @param string $student_id
 * @param string $access_type  'course_access' | 'content_view' | 'live_session' | 'assignment_view'
 * @param int|null $course_id  VLE course ID (if applicable)
 * @param string|null $detail  Additional detail (e.g. content name, session name)
 * @return bool Whether attendance was recorded
 */
function recordAutoAttendance($conn, $student_id, $access_type = 'course_access', $course_id = null, $detail = null) {
    // Ensure table exists
    $conn->query("CREATE TABLE IF NOT EXISTS student_activity_attendance (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id VARCHAR(50) NOT NULL,
        course_id INT NULL,
        access_type ENUM('course_access','content_view','live_session','assignment_view') NOT NULL DEFAULT 'course_access',
        detail VARCHAR(255) NULL,
        access_time DATETIME NOT NULL,
        attendance_date DATE NOT NULL,
        ip_address VARCHAR(45),
        has_paid_tuition TINYINT(1) DEFAULT 0,
        INDEX idx_student_date (student_id, attendance_date),
        INDEX idx_student_course_date (student_id, course_id, attendance_date),
        INDEX idx_access_type (access_type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Check payment status
    $payment = getStudentPaymentStatus($conn, $student_id);
    if (!$payment['has_paid']) {
        return false; // Don't record attendance for unpaid students
    }

    $today = date('Y-m-d');

    // Check if already recorded for this student + course + type + day
    if ($course_id) {
        $check = $conn->prepare("SELECT id FROM student_activity_attendance WHERE student_id = ? AND course_id = ? AND access_type = ? AND attendance_date = ? LIMIT 1");
        $check->bind_param("siss", $student_id, $course_id, $access_type, $today);
    } else {
        $check = $conn->prepare("SELECT id FROM student_activity_attendance WHERE student_id = ? AND course_id IS NULL AND access_type = ? AND attendance_date = ? LIMIT 1");
        $check->bind_param("sss", $student_id, $access_type, $today);
    }
    $check->execute();
    $exists = $check->get_result()->num_rows > 0;
    $check->close();

    if ($exists) {
        return false; // Already recorded today
    }

    // Record attendance
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $now = date('Y-m-d H:i:s');
    $paid_flag = 1;

    $stmt = $conn->prepare("INSERT INTO student_activity_attendance (student_id, course_id, access_type, detail, access_time, attendance_date, ip_address, has_paid_tuition) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("sisssssi", $student_id, $course_id, $access_type, $detail, $now, $today, $ip, $paid_flag);
    $result = $stmt->execute();
    $stmt->close();

    return $result;
}
