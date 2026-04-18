<?php
// student/resources.php - Student Resources Page
// Shows downloadable course materials and resources for the student
require_once '../includes/auth.php';
requireLogin();
requireRole(['student', 'exam_clearance_student', 'dissertation_student']);

$conn = getDbConnection();
$user = getCurrentUser();
$student_id = $_SESSION['vle_related_id'] ?? '';

$breadcrumbs = [['title' => 'Resources']];
$page_title = 'Resources';

// Get materials from enrolled courses
$materials = [];
$sql = "SELECT cm.*, c.course_name, c.course_code
        FROM vle_course_materials cm
        JOIN vle_courses c ON cm.course_id = c.course_id
        JOIN vle_enrollments e ON c.course_id = e.course_id
        WHERE e.student_id = ?
        ORDER BY cm.uploaded_date DESC";
$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param("s", $student_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) $materials[] = $row;
    $stmt->close();
}

// Group by course
$by_course = [];
foreach ($materials as $m) {
    $key = $m['course_code'] . ' - ' . $m['course_name'];
    $by_course[$key][] = $m;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title ?> - VLE Student</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
    <style>body{font-family:'Inter',sans-serif;}</style>
</head>
<body>
<?php include 'header_nav.php'; ?>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold mb-1"><i class="bi bi-folder2-open me-2"></i>Resources</h3>
            <p class="text-muted mb-0">Course materials and downloadable resources</p>
        </div>
        <span class="badge bg-primary fs-6"><?= count($materials) ?> file<?= count($materials) !== 1 ? 's' : '' ?></span>
    </div>

    <?php if (empty($materials)): ?>
        <div class="card shadow-sm border-0">
            <div class="card-body text-center py-5">
                <i class="bi bi-folder2-open" style="font-size:3rem;color:#adb5bd;"></i>
                <h5 class="mt-3">No Resources Available</h5>
                <p class="text-muted">There are currently no course materials available for your enrolled courses.</p>
            </div>
        </div>
    <?php else: ?>
        <?php foreach ($by_course as $course_label => $files): ?>
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-header bg-white">
                <h5 class="mb-0"><i class="bi bi-book me-2 text-primary"></i><?= htmlspecialchars($course_label) ?></h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th style="width:40px;"></th>
                                <th>Title</th>
                                <th>Type</th>
                                <th>Uploaded</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($files as $file):
                            $ext = strtolower(pathinfo($file['file_path'] ?? '', PATHINFO_EXTENSION));
                            $icon = 'bi-file-earmark';
                            if (in_array($ext, ['pdf'])) $icon = 'bi-file-earmark-pdf text-danger';
                            elseif (in_array($ext, ['doc','docx'])) $icon = 'bi-file-earmark-word text-primary';
                            elseif (in_array($ext, ['xls','xlsx'])) $icon = 'bi-file-earmark-excel text-success';
                            elseif (in_array($ext, ['ppt','pptx'])) $icon = 'bi-file-earmark-ppt text-warning';
                            elseif (in_array($ext, ['jpg','jpeg','png','gif'])) $icon = 'bi-file-earmark-image text-info';
                            elseif (in_array($ext, ['zip','rar','7z'])) $icon = 'bi-file-earmark-zip text-secondary';
                            elseif (in_array($ext, ['mp4','avi','mov'])) $icon = 'bi-file-earmark-play text-danger';
                        ?>
                            <tr>
                                <td><i class="bi <?= $icon ?>" style="font-size:1.3rem;"></i></td>
                                <td>
                                    <strong><?= htmlspecialchars($file['title'] ?? $file['file_name'] ?? 'Untitled') ?></strong>
                                    <?php if (!empty($file['description'])): ?>
                                        <br><small class="text-muted"><?= htmlspecialchars($file['description']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><span class="badge bg-light text-dark"><?= strtoupper($ext ?: 'FILE') ?></span></td>
                                <td><small class="text-muted"><?= !empty($file['uploaded_date']) ? date('M j, Y', strtotime($file['uploaded_date'])) : '—' ?></small></td>
                                <td class="text-end">
                                    <?php if (!empty($file['file_path'])): ?>
                                        <a href="../<?= htmlspecialchars($file['file_path']) ?>" class="btn btn-sm btn-outline-primary" target="_blank">
                                            <i class="bi bi-download me-1"></i>Download
                                        </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/js/theme-switcher.js"></script>
<script src="../assets/js/auto-logout.js"></script>
</body>
</html>
