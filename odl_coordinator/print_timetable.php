<?php
/**
 * ODL Coordinator - Print Timetable
 * Printable view of class timetable
 */

require_once '../includes/auth.php';
requireLogin();
requireRole(['odl_coordinator', 'admin', 'staff']);

$conn = getDbConnection();

$filter_course = $_GET['course'] ?? '';
$filter_program = $_GET['program'] ?? '';

$where = ["t.is_active = 1"];
$params = [];
$types = "";

if ($filter_course) {
    $where[] = "t.course_id = ?";
    $params[] = $filter_course;
    $types .= "i";
}

$where_sql = implode(" AND ", $where);

$sql = "
    SELECT t.*, c.course_code, c.course_name, c.program_of_study, l.full_name as lecturer_name
    FROM class_timetable t
    JOIN vle_courses c ON t.course_id = c.course_id
    LEFT JOIN lecturers l ON c.lecturer_id = l.lecturer_id
    WHERE $where_sql
    ORDER BY FIELD(t.day_of_week, 'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'), t.start_time
";

if (!empty($params)) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $result = $conn->query($sql);
}

$timetable = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $day = $row['day_of_week'];
        if (!isset($timetable[$day])) {
            $timetable[$day] = [];
        }
        $timetable[$day][] = $row;
    }
}

$days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Class Timetable - Print View</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: Arial, sans-serif; font-size: 11px; padding: 20px; }
        .header { text-align: center; margin-bottom: 20px; padding-bottom: 10px; border-bottom: 2px solid #333; }
        .header h1 { font-size: 18px; margin-bottom: 5px; }
        .header p { color: #666; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        th, td { border: 1px solid #333; padding: 6px 8px; text-align: left; }
        th { background: #f0f0f0; font-weight: bold; }
        .day-header { background: #333; color: white; font-size: 12px; }
        .time { font-family: monospace; white-space: nowrap; }
        .course-code { font-weight: bold; }
        .session-type { 
            display: inline-block; 
            padding: 2px 6px; 
            border-radius: 3px; 
            font-size: 9px;
            background: #e0e0e0;
        }
        .footer { margin-top: 30px; padding-top: 10px; border-top: 1px solid #ccc; font-size: 10px; color: #666; }
        @media print {
            body { padding: 0; }
            .no-print { display: none; }
        }
    </style>
</head>
<body>
    <div class="no-print" style="margin-bottom: 20px;">
        <button onclick="window.print()" style="padding: 10px 20px; cursor: pointer;">🖨️ Print Timetable</button>
        <a href="manage_timetable.php" style="margin-left: 10px;">← Back to Management</a>
    </div>
    
    <div class="header">
        <h1>CLASS TIMETABLE</h1>
        <p>ODL Program - Academic Year <?= date('Y') . '/' . (date('Y')+1) ?></p>
        <p>Generated: <?= date('F j, Y') ?></p>
    </div>
    
    <table>
        <thead>
            <tr>
                <th style="width: 100px;">Time</th>
                <th>Course</th>
                <th>Lecturer</th>
                <th style="width: 100px;">Venue</th>
                <th style="width: 80px;">Type</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($days as $day): ?>
            <tr>
                <td colspan="5" class="day-header"><?= strtoupper($day) ?></td>
            </tr>
            <?php if (isset($timetable[$day]) && !empty($timetable[$day])): ?>
                <?php foreach ($timetable[$day] as $t): ?>
                <tr>
                    <td class="time"><?= date('H:i', strtotime($t['start_time'])) ?> - <?= date('H:i', strtotime($t['end_time'])) ?></td>
                    <td>
                        <span class="course-code"><?= htmlspecialchars($t['course_code']) ?></span><br>
                        <?= htmlspecialchars($t['course_name']) ?>
                    </td>
                    <td><?= htmlspecialchars($t['lecturer_name'] ?? 'TBA') ?></td>
                    <td><?= htmlspecialchars($t['venue'] ?: 'TBA') ?></td>
                    <td><span class="session-type"><?= ucfirst($t['session_type']) ?></span></td>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="5" style="text-align: center; color: #999; font-style: italic;">No classes scheduled</td></tr>
            <?php endif; ?>
            <?php endforeach; ?>
        </tbody>
    </table>
    
    <div class="footer">
        <p><strong>Note:</strong> This timetable is subject to change. Please check the online portal for updates.</p>
        <p>Printed by ODL Coordinator Office</p>
    </div>
</body>
</html>
