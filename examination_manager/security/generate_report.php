<?php
// examination_manager/security/generate_report.php - Generate security reports
require_once '../../includes/auth.php';
requireLogin();
requireRole(['staff', 'admin', 'examination_manager']);

$conn = getDbConnection();
$user = getCurrentUser();

$reportType = $_GET['type'] ?? 'incidents';

switch ($reportType) {
    case 'incidents':
        generateIncidentsReport($conn);
        break;
    case 'sessions':
        generateSessionsReport($conn);
        break;
    default:
        http_response_code(400);
        echo 'Invalid report type';
        exit;
}

function generateIncidentsReport($conn) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="security_incidents_' . date('Y-m-d_H-i-s') . '.csv"');

    $output = fopen('php://output', 'w');

    // CSV headers
    fputcsv($output, [
        'Session ID',
        'Student Name',
        'Student Number',
        'Exam Title',
        'Incident Type',
        'Timestamp',
        'IP Address',
        'Event Data'
    ]);

    // Get incident data
    $query = "
        SELECT
            em.session_id,
            s.first_name,
            s.last_name,
            s.student_number,
            e.title as exam_title,
            em.event_type,
            em.timestamp,
            em.ip_address,
            em.event_data
        FROM exam_monitoring em
        JOIN exam_sessions es ON em.session_id = es.session_id
        JOIN students s ON es.student_id = s.student_id
        JOIN exams e ON es.exam_id = e.exam_id
        WHERE em.event_type IN ('tab_visibility_change', 'fullscreen_exited', 'camera_snapshot')
        ORDER BY em.timestamp DESC
    ";

    $result = $conn->query($query);

    while ($row = $result->fetch_assoc()) {
        fputcsv($output, [
            $row['session_id'],
            $row['first_name'] . ' ' . $row['last_name'],
            $row['student_number'],
            $row['exam_title'],
            $row['event_type'],
            $row['timestamp'],
            $row['ip_address'],
            $row['event_data']
        ]);
    }

    fclose($output);
    exit;
}

function generateSessionsReport($conn) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="session_activity_' . date('Y-m-d_H-i-s') . '.csv"');

    $output = fopen('php://output', 'w');

    // CSV headers
    fputcsv($output, [
        'Session ID',
        'Student Name',
        'Student Number',
        'Exam Title',
        'Start Time',
        'End Time',
        'Duration (minutes)',
        'Total Events',
        'Suspicious Events',
        'Status'
    ]);

    // Get session data
    $query = "
        SELECT
            es.session_id,
            s.first_name,
            s.last_name,
            s.student_number,
            e.title as exam_title,
            es.started_at,
            es.submitted_at,
            e.duration_minutes,
            COUNT(em.monitoring_id) as total_events,
            SUM(CASE WHEN em.event_type IN ('tab_visibility_change', 'fullscreen_exited') THEN 1 ELSE 0 END) as suspicious_events,
            CASE WHEN es.is_active = 1 THEN 'Active' ELSE 'Completed' END as status
        FROM exam_sessions es
        JOIN exams e ON es.exam_id = e.exam_id
        JOIN students s ON es.student_id = s.student_id
        LEFT JOIN exam_monitoring em ON es.session_id = em.session_id
        GROUP BY es.session_id, s.first_name, s.last_name, s.student_number, e.title, es.started_at, es.submitted_at, e.duration_minutes, es.is_active
        ORDER BY es.started_at DESC
    ";

    $result = $conn->query($query);

    while ($row = $result->fetch_assoc()) {
        fputcsv($output, [
            $row['session_id'],
            $row['first_name'] . ' ' . $row['last_name'],
            $row['student_number'],
            $row['exam_title'],
            $row['started_at'],
            $row['submitted_at'],
            $row['duration_minutes'],
            $row['total_events'],
            $row['suspicious_events'],
            $row['status']
        ]);
    }

    fclose($output);
    exit;
}
?>