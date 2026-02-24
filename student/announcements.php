<?php
// announcements.php - Student Announcements Page
require_once '../includes/auth.php';
requireLogin();
requireRole(['student']);

$conn = getDbConnection();
$student_id = $_SESSION['vle_related_id'];

// Pagination settings
$announcements_per_page = 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $announcements_per_page;

// Get student's announcements from enrolled courses with pagination
$announcements = [];
$announcements_query = "SELECT a.*, c.course_name, l.full_name as lecturer_name 
                       FROM vle_announcements a
                       JOIN vle_courses c ON a.course_id = c.course_id
                       JOIN lecturers l ON a.lecturer_id = l.lecturer_id
                       JOIN vle_enrollments e ON c.course_id = e.course_id
                       WHERE e.student_id = ?
                       ORDER BY a.created_date DESC
                       LIMIT ? OFFSET ?";

$stmt = $conn->prepare($announcements_query);
$stmt->bind_param("sii", $student_id, $announcements_per_page, $offset);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $announcements[] = $row;
}
$stmt->close();

// Get total count for pagination
$count_query = "SELECT COUNT(*) as total FROM vle_announcements a
                JOIN vle_courses c ON a.course_id = c.course_id
                JOIN vle_enrollments e ON c.course_id = e.course_id
                WHERE e.student_id = ?";
$stmt = $conn->prepare($count_query);
$stmt->bind_param("s", $student_id);
$stmt->execute();
$result = $stmt->get_result();
$total_announcements = $result->fetch_assoc()['total'];
$stmt->close();

$total_pages = ceil($total_announcements / $announcements_per_page);

// Set breadcrumb
$breadcrumbs = [
    ['title' => 'Dashboard', 'url' => 'dashboard.php'],
    ['title' => 'Announcements', 'url' => '']
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Announcements - Student Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.8.1/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
    <style>
        .announcement-card {
            transition: all 0.3s ease;
            border: none;
            border-radius: var(--vle-radius-lg);
            overflow: hidden;
        }
        .announcement-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(30, 60, 114, 0.12);
        }
        .announcement-meta {
            font-size: 0.875rem;
        }
        .announcement-content {
            line-height: 1.6;
        }
        .stat-card-mini {
            border: none;
            border-radius: var(--vle-radius-lg);
            background: white;
            box-shadow: var(--vle-shadow);
        }
        .stat-card-mini .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }
        .announcement-header {
            background: var(--vle-gradient-primary);
            border-radius: var(--vle-radius-lg);
            padding: 1.5rem 2rem;
            margin-bottom: 1.5rem;
            color: white;
        }
    </style>
</head>
<body>
    <?php require_once 'header_nav.php'; ?>
    
    <div class="vle-content">
        
        <!-- Header -->
        <div class="announcement-header">
            <div class="d-flex flex-wrap justify-content-between align-items-center">
                <div>
                    <h1 class="h3 mb-1">
                        <i class="bi bi-megaphone me-2"></i>Course Announcements
                    </h1>
                    <p class="mb-0 opacity-75">Stay updated with announcements from your enrolled courses</p>
                </div>
                <a href="dashboard.php" class="btn btn-light mt-2 mt-md-0">
                    <i class="bi bi-arrow-left"></i> Back to Dashboard
                </a>
            </div>
        </div>

        <!-- Statistics -->
        <div class="row mb-4">
            <div class="col-md-6 col-lg-3 mb-3 mb-lg-0">
                <div class="card stat-card-mini">
                    <div class="card-body text-center py-4">
                        <div class="stat-icon mx-auto mb-2" style="background: var(--vle-gradient-primary); color: white;">
                            <i class="bi bi-bell-fill"></i>
                        </div>
                        <h5 class="card-title mb-1" style="color: var(--vle-primary);"><?php echo $total_announcements; ?></h5>
                        <p class="card-text text-muted small mb-0">Total Announcements</p>
                    </div>
                </div>
            </div>
            <div class="col-md-6 col-lg-3">
                <div class="card stat-card-mini">
                    <div class="card-body text-center py-4">
                        <div class="stat-icon mx-auto mb-2" style="background: var(--vle-gradient-success); color: white;">
                            <i class="bi bi-journal-check"></i>
                        </div>
                        <h5 class="card-title mb-1" style="color: var(--vle-success);"><?php echo count($announcements); ?></h5>
                        <p class="card-text text-muted small mb-0">This Page</p>
                    </div>
                </div>
            </div>
        </div>

        <?php if (!empty($announcements)): ?>
            <!-- Announcements List -->
            <div class="row">
                <div class="col-12">
                    <?php foreach ($announcements as $announcement): ?>
                        <div class="card vle-card mb-4 announcement-card">
                            <div class="card-header" style="background: linear-gradient(135deg, var(--vle-gray-50) 0%, var(--vle-gray-100) 100%); border-bottom: 1px solid var(--vle-gray-200);">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div class="flex-grow-1">
                                        <h5 class="mb-1" style="color: var(--vle-primary);">
                                            <i class="bi bi-bell-fill me-1"></i> <?php echo htmlspecialchars($announcement['title']); ?>
                                        </h5>
                                        <div class="announcement-meta text-muted">
                                            <i class="bi bi-book"></i> <?php echo htmlspecialchars($announcement['course_name']); ?> |
                                            <i class="bi bi-person"></i> <?php echo htmlspecialchars($announcement['lecturer_name']); ?> |
                                            <i class="bi bi-calendar"></i> <?php echo date('M j, Y \a\t g:i A', strtotime($announcement['created_date'])); ?>
                                        </div>
                                    </div>
                                    <span class="vle-badge-primary">
                                        <?php 
                                        $days_ago = floor((time() - strtotime($announcement['created_date'])) / (60 * 60 * 24));
                                        if ($days_ago == 0) echo 'Today';
                                        elseif ($days_ago == 1) echo 'Yesterday'; 
                                        else echo $days_ago . ' days ago';
                                        ?>
                                    </span>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="announcement-content">
                                    <?php echo nl2br(htmlspecialchars($announcement['content'])); ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="row">
                    <div class="col-12">
                        <nav aria-label="Announcements pagination">
                            <ul class="pagination justify-content-center">
                                <?php if ($page > 1): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?page=<?php echo $page - 1; ?>" style="color: var(--vle-primary);">Previous</a>
                                    </li>
                                <?php endif; ?>
                                
                                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                    <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                        <a class="page-link" href="?page=<?php echo $i; ?>" <?php if ($i == $page): ?>style="background: var(--vle-gradient-primary); border-color: var(--vle-primary);"<?php else: ?>style="color: var(--vle-primary);"<?php endif; ?>><?php echo $i; ?></a>
                                    </li>
                                <?php endfor; ?>
                                
                                <?php if ($page < $total_pages): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?page=<?php echo $page + 1; ?>" style="color: var(--vle-primary);">Next</a>
                                    </li>
                                <?php endif; ?>
                            </ul>
                        </nav>
                    </div>
                </div>
            <?php endif; ?>

        <?php else: ?>
            <!-- Empty State -->
            <div class="row">
                <div class="col-12">
                    <div class="card vle-card">
                        <div class="card-body text-center py-5">
                            <div class="mb-4" style="color: var(--vle-gray-400);">
                                <i class="bi bi-megaphone" style="font-size: 4rem;"></i>
                            </div>
                            <h4 class="mb-3" style="color: var(--vle-gray-600);">No Announcements Found</h4>
                            <p class="mb-4" style="color: var(--vle-gray-500);">
                                There are currently no announcements from your enrolled courses.<br>
                                Check back later for updates from your lecturers.
                            </p>
                            <a href="dashboard.php" class="btn btn-vle-primary">
                                <i class="bi bi-arrow-left"></i> Back to Dashboard
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>