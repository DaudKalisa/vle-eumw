<?php
// course_reports.php - Admin course reports
require_once '../includes/auth.php';
requireLogin();
requireRole(['staff', 'admin']);

$conn = getDbConnection();

// Get all courses with detailed information
$courses = [];
$query = "
    SELECT 
        vc.course_id,
        vc.course_code,
        vc.course_name,
        vc.description,
        vc.total_weeks,
        vc.is_active,
        vc.created_date,
        COALESCE(l.full_name, 'Unassigned') as lecturer_name,
        COALESCE(l.department, 'N/A') as department,
        COUNT(DISTINCT ve.enrollment_id) as student_count,
        COUNT(DISTINCT CASE WHEN s.gender = 'Male' THEN ve.enrollment_id END) as male_count,
        COUNT(DISTINCT CASE WHEN s.gender = 'Female' THEN ve.enrollment_id END) as female_count,
        COUNT(DISTINCT CASE WHEN s.gender = 'Other' THEN ve.enrollment_id END) as other_count,
        GROUP_CONCAT(DISTINCT s.program ORDER BY s.program SEPARATOR ', ') as programs
    FROM vle_courses vc
    LEFT JOIN lecturers l ON vc.lecturer_id = l.lecturer_id
    LEFT JOIN vle_enrollments ve ON vc.course_id = ve.course_id
    LEFT JOIN students s ON ve.student_id = s.student_id
    GROUP BY vc.course_id, vc.course_code, vc.course_name, vc.description, 
             vc.total_weeks, vc.is_active, vc.created_date, l.full_name, l.department
    ORDER BY vc.course_name
";

$result = $conn->query($query);
while ($row = $result->fetch_assoc()) {
    $courses[] = $row;
}

// Note: Don't close $conn here - header_nav.php needs it for getCurrentUser()
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Course Reports - Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
    <style>
        .gender-chart {
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .gender-bar {
            height: 20px;
            display: inline-block;
        }
        
        /* Print Styles */
        @media print {
            .no-print {
                display: none !important;
            }
            .navbar {
                display: none !important;
            }
            body {
                background: white !important;
            }
            .card {
                border: 1px solid #000 !important;
                box-shadow: none !important;
                page-break-inside: avoid;
            }
            .card-header {
                background-color: #f8f9fa !important;
                color: #000 !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            table {
                page-break-inside: auto;
            }
            tr {
                page-break-inside: avoid;
                page-break-after: auto;
            }
            thead {
                display: table-header-group;
            }
            .print-header {
                display: block !important;
                text-align: center;
                margin-bottom: 20px;
            }
            .print-header h2 {
                margin: 0;
                font-size: 24px;
            }
            .print-header p {
                margin: 5px 0;
                font-size: 14px;
            }
        }
        
        .print-header {
            display: none;
        }
    </style>
</head>
<body>
    <?php 
    $currentPage = 'course_reports';
    $pageTitle = 'Course Reports';
    $breadcrumbs = [['title' => 'Reports']];
    include 'header_nav.php'; 
    ?>

    <div class="vle-content">
        <!-- Print Header (only visible when printing) -->
        <div class="print-header">
            <h2>VLE System - Course Reports</h2>
            <p>Generated on: <?php echo date('F d, Y'); ?></p>
            <p>Total Courses: <?php echo count($courses); ?></p>
        </div>
        
        <div class="vle-page-header mb-4 no-print">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h1 class="h3 mb-1"><i class="bi bi-graph-up me-2"></i>Course Reports</h1>
                    <p class="text-muted mb-0">Generate and export course analytics</p>
                </div>
                <div>
                    <button onclick="printReport()" class="btn btn-vle-primary me-2">
                        <i class="bi bi-printer"></i> Print Report
                    </button>
                    <button onclick="exportToExcel()" class="btn btn-vle-accent me-2">
                        <i class="bi bi-file-earmark-excel"></i> Export to Excel
                    </button>
                    <button onclick="exportToPDF()" class="btn btn-danger me-2">
                        <i class="bi bi-file-earmark-pdf"></i> Export to PDF
                    </button>
                </div>
            </div>
        </div>

        <?php if (empty($courses)): ?>
            <div class="alert vle-alert-info">
                <i class="bi bi-info-circle"></i> No courses found in the system.
            </div>
        <?php else: ?>
            <div class="card vle-card">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0"><i class="bi bi-list-ul"></i> All Courses (<?php echo count($courses); ?>)</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped table-hover">
                            <thead class="table-dark">
                                <tr>
                                    <th width="8%">Code</th>
                                    <th width="18%">Course Name</th>
                                    <th width="15%">Lecturer</th>
                                    <th width="12%">Department</th>
                                    <th width="8%">Students</th>
                                    <th width="15%">Gender Distribution</th>
                                    <th width="20%">Programs of Study</th>
                                    <th width="8%">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($courses as $course): ?>
                                    <tr>
                                        <td><span class="badge bg-primary"><?php echo htmlspecialchars($course['course_code']); ?></span></td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($course['course_name']); ?></strong>
                                            <br><small class="text-muted"><?php echo $course['total_weeks']; ?> weeks</small>
                                        </td>
                                        <td>
                                            <?php if ($course['lecturer_name'] === 'Unassigned'): ?>
                                                <span class="badge bg-warning text-dark">
                                                    <i class="bi bi-exclamation-triangle"></i> Unassigned
                                                </span>
                                            <?php else: ?>
                                                <i class="bi bi-person-badge"></i> <?php echo htmlspecialchars($course['lecturer_name']); ?>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($course['department'] === 'N/A'): ?>
                                                <span class="text-muted">N/A</span>
                                            <?php else: ?>
                                                <i class="bi bi-building"></i> <?php echo htmlspecialchars($course['department']); ?>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge bg-info">
                                                <i class="bi bi-people"></i> <?php echo $course['student_count']; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($course['student_count'] > 0): ?>
                                                <small>
                                                    <i class="bi bi-gender-male text-primary"></i> Male: <?php echo $course['male_count']; ?><br>
                                                    <i class="bi bi-gender-female text-danger"></i> Female: <?php echo $course['female_count']; ?>
                                                    <?php if ($course['other_count'] > 0): ?>
                                                        <br><i class="bi bi-person"></i> Other: <?php echo $course['other_count']; ?>
                                                    <?php endif; ?>
                                                </small>
                                                <div class="gender-chart mt-1">
                                                    <?php 
                                                    $total = $course['student_count'];
                                                    $malePercent = ($course['male_count'] / $total) * 100;
                                                    $femalePercent = ($course['female_count'] / $total) * 100;
                                                    $otherPercent = ($course['other_count'] / $total) * 100;
                                                    ?>
                                                    <?php if ($malePercent > 0): ?>
                                                        <div class="gender-bar bg-primary" style="width: <?php echo $malePercent; ?>%" 
                                                             title="Male: <?php echo round($malePercent, 1); ?>%"></div>
                                                    <?php endif; ?>
                                                    <?php if ($femalePercent > 0): ?>
                                                        <div class="gender-bar bg-danger" style="width: <?php echo $femalePercent; ?>%" 
                                                             title="Female: <?php echo round($femalePercent, 1); ?>%"></div>
                                                    <?php endif; ?>
                                                    <?php if ($otherPercent > 0): ?>
                                                        <div class="gender-bar bg-secondary" style="width: <?php echo $otherPercent; ?>%" 
                                                             title="Other: <?php echo round($otherPercent, 1); ?>%"></div>
                                                    <?php endif; ?>
                                                </div>
                                            <?php else: ?>
                                                <span class="text-muted">No students</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($course['programs'])): ?>
                                                <small><?php echo htmlspecialchars($course['programs']); ?></small>
                                            <?php else: ?>
                                                <span class="text-muted">No enrollments</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($course['is_active']): ?>
                                                <span class="badge bg-success">Active</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Inactive</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Summary Statistics -->
            <div class="row mt-4 no-print">
                <div class="col-md-3">
                    <div class="card text-center">
                        <div class="card-body">
                            <i class="bi bi-book-fill text-primary" style="font-size: 2rem;"></i>
                            <h5 class="mt-2"><?php echo count($courses); ?></h5>
                            <p class="text-muted">Total Courses</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-center">
                        <div class="card-body">
                            <i class="bi bi-person-badge-fill text-success" style="font-size: 2rem;"></i>
                            <h5 class="mt-2">
                                <?php 
                                $assignedCourses = array_filter($courses, function($c) { 
                                    return $c['lecturer_name'] !== 'Unassigned'; 
                                });
                                echo count($assignedCourses); 
                                ?>
                            </h5>
                            <p class="text-muted">Assigned Courses</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-center">
                        <div class="card-body">
                            <i class="bi bi-exclamation-triangle-fill text-warning" style="font-size: 2rem;"></i>
                            <h5 class="mt-2">
                                <?php 
                                $unassignedCourses = array_filter($courses, function($c) { 
                                    return $c['lecturer_name'] === 'Unassigned'; 
                                });
                                echo count($unassignedCourses); 
                                ?>
                            </h5>
                            <p class="text-muted">Unassigned Courses</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-center">
                        <div class="card-body">
                            <i class="bi bi-people-fill text-info" style="font-size: 2rem;"></i>
                            <h5 class="mt-2">
                                <?php 
                                $totalEnrollments = array_sum(array_column($courses, 'student_count'));
                                echo $totalEnrollments; 
                                ?>
                            </h5>
                            <p class="text-muted">Total Enrollments</p>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.31/jspdf.plugin.autotable.min.js"></script>
    
    <script>
        function printReport() {
            window.print();
        }
        
        function exportToExcel() {
            // Get the table
            const table = document.querySelector('.table-responsive table');
            
            // Create a workbook
            const wb = XLSX.utils.book_new();
            
            // Create data array for the worksheet
            const data = [];
            
            // Add header
            data.push(['Course Code', 'Course Name', 'Lecturer', 'Department', 'Total Students', 
                       'Male Students', 'Female Students', 'Other Students', 'Programs of Study', 'Status']);
            
            // Add data rows
            const rows = table.querySelectorAll('tbody tr');
            rows.forEach(row => {
                const cells = row.querySelectorAll('td');
                const courseCode = cells[0].textContent.trim();
                const courseName = cells[1].querySelector('strong').textContent.trim();
                const lecturer = cells[2].textContent.trim().replace(/\s+/g, ' ');
                const department = cells[3].textContent.trim().replace(/\s+/g, ' ');
                const totalStudents = cells[4].textContent.trim();
                
                // Extract gender data
                let maleCount = 0, femaleCount = 0, otherCount = 0;
                const genderText = cells[5].textContent;
                const maleMatch = genderText.match(/Male:\s*(\d+)/);
                const femaleMatch = genderText.match(/Female:\s*(\d+)/);
                const otherMatch = genderText.match(/Other:\s*(\d+)/);
                
                if (maleMatch) maleCount = parseInt(maleMatch[1]);
                if (femaleMatch) femaleCount = parseInt(femaleMatch[1]);
                if (otherMatch) otherCount = parseInt(otherMatch[1]);
                
                const programs = cells[6].textContent.trim();
                const status = cells[7].textContent.trim();
                
                data.push([courseCode, courseName, lecturer, department, totalStudents, 
                          maleCount, femaleCount, otherCount, programs, status]);
            });
            
            // Create worksheet
            const ws = XLSX.utils.aoa_to_sheet(data);
            
            // Set column widths
            ws['!cols'] = [
                {wch: 12}, {wch: 30}, {wch: 20}, {wch: 20}, {wch: 12}, 
                {wch: 12}, {wch: 12}, {wch: 12}, {wch: 30}, {wch: 10}
            ];
            
            // Add worksheet to workbook
            XLSX.utils.book_append_sheet(wb, ws, 'Course Reports');
            
            // Generate filename with current date
            const filename = 'Course_Reports_' + new Date().toISOString().split('T')[0] + '.xlsx';
            
            // Save file
            XLSX.writeFile(wb, filename);
        }
        
        function exportToPDF() {
            const { jsPDF } = window.jspdf;
            const doc = new jsPDF('landscape');
            
            // Add title
            doc.setFontSize(18);
            doc.text('VLE System - Course Reports', 14, 15);
            
            doc.setFontSize(11);
            doc.text('Generated on: ' + new Date().toLocaleDateString(), 14, 22);
            
            // Get table data
            const table = document.querySelector('.table-responsive table');
            const rows = [];
            
            // Add data
            table.querySelectorAll('tbody tr').forEach(row => {
                const cells = row.querySelectorAll('td');
                const courseCode = cells[0].textContent.trim();
                const courseName = cells[1].querySelector('strong').textContent.trim();
                const lecturer = cells[2].textContent.trim().replace(/\s+/g, ' ');
                const department = cells[3].textContent.trim().replace(/\s+/g, ' ');
                const students = cells[4].textContent.trim();
                
                // Extract gender data
                const genderText = cells[5].textContent;
                let genderInfo = '';
                const maleMatch = genderText.match(/Male:\s*(\d+)/);
                const femaleMatch = genderText.match(/Female:\s*(\d+)/);
                if (maleMatch && femaleMatch) {
                    genderInfo = `M:${maleMatch[1]} F:${femaleMatch[1]}`;
                }
                
                const programs = cells[6].textContent.trim().substring(0, 25) + (cells[6].textContent.trim().length > 25 ? '...' : '');
                const status = cells[7].textContent.trim();
                
                rows.push([courseCode, courseName, lecturer, department, students, genderInfo, programs, status]);
            });
            
            // Add table
            doc.autoTable({
                head: [['Code', 'Course Name', 'Lecturer', 'Department', 'Students', 'Gender', 'Programs', 'Status']],
                body: rows,
                startY: 28,
                styles: { fontSize: 8, cellPadding: 2 },
                headStyles: { fillColor: [52, 58, 64], textColor: 255 },
                columnStyles: {
                    0: { cellWidth: 20 },
                    1: { cellWidth: 45 },
                    2: { cellWidth: 35 },
                    3: { cellWidth: 35 },
                    4: { cellWidth: 18 },
                    5: { cellWidth: 20 },
                    6: { cellWidth: 45 },
                    7: { cellWidth: 18 }
                },
                margin: { top: 28 }
            });
            
            // Add footer with page numbers
            const pageCount = doc.internal.getNumberOfPages();
            for (let i = 1; i <= pageCount; i++) {
                doc.setPage(i);
                doc.setFontSize(8);
                doc.text('Page ' + i + ' of ' + pageCount, doc.internal.pageSize.getWidth() - 20, doc.internal.pageSize.getHeight() - 10);
            }
            
            // Save PDF
            const filename = 'Course_Reports_' + new Date().toISOString().split('T')[0] + '.pdf';
            doc.save(filename);
        }
    </script>
</body>
</html>
