<?php
/**
 * Research Coordinator - Defense Schedule PDF
 * Generates a printable PDF list of scheduled defenses with timeline.
 */
session_start();
require_once '../includes/auth.php';
require_once '../vendor/autoload.php';
requireLogin();
requireRole(['research_coordinator', 'admin']);

$conn = getDbConnection();

// Fetch scheduled defenses
$defenses = [];
$r = $conn->query("\
    SELECT dd.*, d.title as dissertation_title, d.student_id, d.program, d.academic_year, \n\
           s.full_name as student_name, l.full_name as supervisor_name\
    FROM dissertation_defense dd\
    JOIN dissertations d ON dd.dissertation_id = d.dissertation_id\
    LEFT JOIN students s ON d.student_id = s.student_id\
    LEFT JOIN lecturers l ON d.supervisor_id = l.lecturer_id\
    WHERE dd.status = 'scheduled'\
    ORDER BY dd.defense_date ASC\
");
if ($r) {
    while ($row = $r->fetch_assoc()) {
        $defenses[] = $row;
    }
}

$html = '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Defense Schedule</title><style>';
$html .= '@page { size: A4; margin: 15mm 15mm; }';
$html .= 'body{font-family:Arial,Helvetica,sans-serif;font-size:12pt;margin:0;padding:0;color:#000;}';
$html .= '.sheet{width:100%;padding:10mm 10mm;}';
$html .= '.header{text-align:center;margin-bottom:14px;}';
$html .= '.header h1{font-size:16pt;margin:0;}';
$html .= '.header p{margin:4px 0;font-size:12pt;color:#333;}';
$html .= '.table{width:100%;border-collapse:collapse;margin-top:12px;}';
$html .= '.table th, .table td{border:1px solid #000;padding:6px 8px;font-size:10pt;vertical-align:top;}';
$html .= '.table th{background:#f0f0f0;}';
$html .= '.small{font-size:10pt;color:#555;}';
$html .= '</style></head><body><div class="sheet">';
$html .= '<div class="header">';
$html .= '<h1>Defense Schedule</h1>';
$html .= '<p class="small">Generated on ' . date('F j, Y \a\t H:i') . '</p>';
$html .= '</div>';

if (empty($defenses)) {
    $html .= '<p style="text-align:center;color:#666;">No scheduled defenses found.</p>';
} else {
    $html .= '<table class="table"><thead><tr>';
    $html .= '<th>#</th><th>Date</th><th>Time</th><th>Student</th><th>Student ID</th><th>Program</th><th>Supervisor</th><th>Title</th><th>Venue</th><th>Type</th>';
    $html .= '</tr></thead><tbody>';

    foreach ($defenses as $i => $d) {
        $date = !empty($d['defense_date']) ? date('M j, Y', strtotime($d['defense_date'])) : '-';
        $time = !empty($d['defense_date']) ? date('h:i A', strtotime($d['defense_date'])) : '-';
        $venue = $d['venue'] ?: ($d['is_virtual'] ? 'Virtual' : '-');
        $html .= '<tr>';
        $html .= '<td>' . ($i + 1) . '</td>';
        $html .= '<td>' . htmlspecialchars($date) . '</td>';
        $html .= '<td>' . htmlspecialchars($time) . '</td>';
        $html .= '<td>' . htmlspecialchars($d['student_name'] ?? '') . '</td>';
        $html .= '<td>' . htmlspecialchars($d['student_id'] ?? '') . '</td>';
        $html .= '<td>' . htmlspecialchars($d['program'] ?? '') . '</td>';
        $html .= '<td>' . htmlspecialchars($d['supervisor_name'] ?? '') . '</td>';
        $html .= '<td>' . htmlspecialchars($d['dissertation_title'] ?? '') . '</td>';
        $html .= '<td>' . htmlspecialchars($venue) . '</td>';
        $html .= '<td>' . htmlspecialchars(ucfirst($d['defense_type'] ?? '')) . '</td>';
        $html .= '</tr>';
    }

    $html .= '</tbody></table>';
}

$html .= '</div></body></html>';

$mpdf = new \Mpdf\Mpdf(['tempDir' => __DIR__ . '/../tmp']);
$mpdf->WriteHTML($html);
$mpdf->Output('defense_schedule.pdf', 'I');
