<?php
/**
 * Dean Portal - Performance Dashboard
 * View faculty performance metrics
 */

require_once '../includes/auth.php';
requireLogin();
requireRole(['dean', 'admin']);

$conn = getDbConnection();

// Get performance metrics
$metrics = [];

// Course completion rates
$course_table = $conn->query("SHOW TABLES LIKE 'vle_courses'")->num_rows > 0 ? 'vle_courses' : 'courses';
$courses_result = $conn->query("SELECT COUNT(*) as total FROM $course_table");
$metrics['total_courses'] = $courses_result ? $courses_result->fetch_assoc()['total'] : 0;

// Assignment completion
$table_check = $conn->query("SHOW TABLES LIKE 'vle_submissions'");
if ($table_check && $table_check->num_rows > 0) {
    $graded = $conn->query("SELECT COUNT(*) as total FROM vle_submissions WHERE score IS NOT NULL")->fetch_assoc()['total'];
    $total_sub = $conn->query("SELECT COUNT(*) as total FROM vle_submissions")->fetch_assoc()['total'];
    $metrics['grading_rate'] = $total_sub > 0 ? round(($graded / $total_sub) * 100) : 0;
    $metrics['total_submissions'] = $total_sub;
    $metrics['graded_submissions'] = $graded;
} else {
    $metrics['grading_rate'] = 0;
    $metrics['total_submissions'] = 0;
    $metrics['graded_submissions'] = 0;
}

// Lecturer productivity
$lecturer_result = $conn->query("SELECT COUNT(*) as total FROM lecturers");
$metrics['total_lecturers'] = $lecturer_result ? $lecturer_result->fetch_assoc()['total'] : 0;

// Claims processed this month
$claims_result = $conn->query("SELECT COUNT(*) as total, SUM(total_amount) as amount FROM lecturer_finance_requests WHERE MONTH(request_date) = MONTH(CURDATE()) AND YEAR(request_date) = YEAR(CURDATE())");
$claims_data = $claims_result ? $claims_result->fetch_assoc() : ['total' => 0, 'amount' => 0];
$metrics['monthly_claims'] = $claims_data['total'] ?? 0;
$metrics['monthly_amount'] = $claims_data['amount'] ?? 0;

// Top lecturers by claims
$top_lecturers = [];
$result = $conn->query("
    SELECT l.full_name, l.department, 
           COUNT(r.request_id) as claim_count, 
           SUM(r.total_amount) as total_amount
    FROM lecturers l
    JOIN lecturer_finance_requests r ON l.lecturer_id = r.lecturer_id
    GROUP BY l.lecturer_id
    ORDER BY total_amount DESC
    LIMIT 10
");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $top_lecturers[] = $row;
    }
}

// Monthly trends (last 6 months)
$monthly_trends = [];
for ($i = 5; $i >= 0; $i--) {
    $month = date('Y-m', strtotime("-$i months"));
    $result = $conn->query("SELECT COUNT(*) as claims, COALESCE(SUM(total_amount), 0) as amount FROM lecturer_finance_requests WHERE DATE_FORMAT(request_date, '%Y-%m') = '$month'");
    $data = $result ? $result->fetch_assoc() : ['claims' => 0, 'amount' => 0];
    $monthly_trends[] = [
        'month' => date('M Y', strtotime($month . '-01')),
        'claims' => $data['claims'],
        'amount' => $data['amount']
    ];
}

$page_title = "Performance";
$breadcrumbs = [['title' => 'Performance']];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title ?> - Dean Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
    <?php include 'header_nav.php'; ?>
    
    <div class="container-fluid py-4">
        <!-- Key Metrics -->
        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="card text-center h-100">
                    <div class="card-body">
                        <div class="fs-2 fw-bold text-primary"><?= $metrics['total_courses'] ?></div>
                        <small class="text-muted">Total Courses</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center h-100">
                    <div class="card-body">
                        <div class="fs-2 fw-bold text-success"><?= $metrics['grading_rate'] ?>%</div>
                        <small class="text-muted">Grading Completion</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center h-100">
                    <div class="card-body">
                        <div class="fs-2 fw-bold text-info"><?= $metrics['monthly_claims'] ?></div>
                        <small class="text-muted">Claims This Month</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center h-100">
                    <div class="card-body">
                        <div class="fs-4 fw-bold text-warning">MK <?= number_format($metrics['monthly_amount']) ?></div>
                        <small class="text-muted">Monthly Claim Amount</small>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="row g-4">
            <!-- Monthly Trends Chart -->
            <div class="col-md-8">
                <div class="card h-100">
                    <div class="card-header bg-white">
                        <h5 class="mb-0"><i class="bi bi-graph-up me-2"></i>Claims Trend (Last 6 Months)</h5>
                    </div>
                    <div class="card-body">
                        <canvas id="trendsChart" height="200"></canvas>
                    </div>
                </div>
            </div>
            
            <!-- Assignment Stats -->
            <div class="col-md-4">
                <div class="card h-100">
                    <div class="card-header bg-white">
                        <h5 class="mb-0"><i class="bi bi-clipboard-data me-2"></i>Assignment Stats</h5>
                    </div>
                    <div class="card-body">
                        <canvas id="gradingChart" height="200"></canvas>
                        <div class="mt-3 text-center">
                            <div class="row">
                                <div class="col-6">
                                    <div class="fs-4 fw-bold text-success"><?= $metrics['graded_submissions'] ?></div>
                                    <small class="text-muted">Graded</small>
                                </div>
                                <div class="col-6">
                                    <div class="fs-4 fw-bold text-warning"><?= $metrics['total_submissions'] - $metrics['graded_submissions'] ?></div>
                                    <small class="text-muted">Pending</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Top Performers -->
        <div class="row g-4 mt-2">
            <div class="col-12">
                <div class="card">
                    <div class="card-header bg-white">
                        <h5 class="mb-0"><i class="bi bi-trophy me-2"></i>Top Lecturers by Claims</h5>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($top_lecturers)): ?>
                        <div class="text-center py-4 text-muted">No data available</div>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>#</th>
                                        <th>Lecturer</th>
                                        <th>Department</th>
                                        <th>Claims</th>
                                        <th>Total Amount</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($top_lecturers as $i => $lecturer): ?>
                                    <tr>
                                        <td>
                                            <?php if ($i < 3): ?>
                                            <span class="badge bg-<?= ['warning', 'secondary', 'danger'][$i] ?>"><?= $i + 1 ?></span>
                                            <?php else: ?>
                                            <?= $i + 1 ?>
                                            <?php endif; ?>
                                        </td>
                                        <td><strong><?= htmlspecialchars($lecturer['full_name']) ?></strong></td>
                                        <td><?= htmlspecialchars($lecturer['department'] ?? 'N/A') ?></td>
                                        <td><span class="badge bg-info"><?= $lecturer['claim_count'] ?></span></td>
                                        <td><strong>MK <?= number_format($lecturer['total_amount']) ?></strong></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Monthly trends chart
        new Chart(document.getElementById('trendsChart'), {
            type: 'line',
            data: {
                labels: <?= json_encode(array_column($monthly_trends, 'month')) ?>,
                datasets: [{
                    label: 'Claims Count',
                    data: <?= json_encode(array_column($monthly_trends, 'claims')) ?>,
                    borderColor: '#0d6efd',
                    backgroundColor: 'rgba(13, 110, 253, 0.1)',
                    fill: true,
                    yAxisID: 'y'
                }, {
                    label: 'Total Amount (MK)',
                    data: <?= json_encode(array_column($monthly_trends, 'amount')) ?>,
                    borderColor: '#198754',
                    backgroundColor: 'transparent',
                    yAxisID: 'y1'
                }]
            },
            options: {
                responsive: true,
                scales: {
                    y: {
                        type: 'linear',
                        position: 'left',
                        title: { display: true, text: 'Claims Count' }
                    },
                    y1: {
                        type: 'linear',
                        position: 'right',
                        title: { display: true, text: 'Amount (MK)' },
                        grid: { drawOnChartArea: false }
                    }
                }
            }
        });
        
        // Grading chart
        new Chart(document.getElementById('gradingChart'), {
            type: 'doughnut',
            data: {
                labels: ['Graded', 'Pending'],
                datasets: [{
                    data: [<?= $metrics['graded_submissions'] ?>, <?= $metrics['total_submissions'] - $metrics['graded_submissions'] ?>],
                    backgroundColor: ['#198754', '#ffc107']
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { position: 'bottom' }
                }
            }
        });
    </script>
</body>
</html>
