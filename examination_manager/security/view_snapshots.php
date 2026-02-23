<?php
// examination_manager/security/view_snapshots.php - View camera snapshots
require_once '../../includes/auth.php';
requireLogin();
requireRole(['staff', 'admin', 'examination_manager']);

$conn = getDbConnection();
$user = getCurrentUser();

// Get snapshots with session information
$query = "
    SELECT
        em.*,
        s.first_name,
        s.last_name,
        s.student_number,
        e.title as exam_title
    FROM exam_monitoring em
    JOIN exam_sessions es ON em.session_id = es.session_id
    JOIN students s ON es.student_id = s.student_id
    JOIN exams e ON es.exam_id = e.exam_id
    WHERE em.event_type = 'camera_snapshot' AND em.snapshot_path IS NOT NULL
    ORDER BY em.timestamp DESC
";

$result = $conn->query($query);
$snapshots = $result->fetch_all(MYSQLI_ASSOC);

$pageTitle = "Camera Snapshots";
$breadcrumbs = [['title' => 'Camera Snapshots']];
include 'header_nav.php';
?>

<div class="vle-content">
    <div class="vle-page-header mb-4">
        <div class="d-flex flex-wrap justify-content-between align-items-center">
            <div>
                <h1 class="h3 mb-1"><i class="bi bi-camera me-2"></i>Camera Snapshots (<?php echo count($snapshots); ?>)</h1>
                <p class="text-muted mb-0">View camera snapshots captured during examinations</p>
            </div>
            <a href="index.php" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left"></i> Back to Security Center
            </a>
        </div>
    </div>

    <?php if (empty($snapshots)): ?>
    <div class="card vle-card">
        <div class="card-body text-center py-5">
            <i class="bi bi-camera display-1 text-muted mb-3"></i>
            <h5 class="text-muted">No Camera Snapshots</h5>
            <p class="text-muted">No camera snapshots have been captured yet.</p>
        </div>
    </div>
    <?php else: ?>
    <div class="row g-3">
        <?php foreach ($snapshots as $snapshot): ?>
        <div class="col-md-4">
            <div class="card vle-card h-100">
                <div class="card-body">
                    <h6><?php echo htmlspecialchars($snapshot['first_name'] . ' ' . $snapshot['last_name']); ?></h6>
                    <small class="text-muted">
                        <?php echo htmlspecialchars($snapshot['student_number']); ?> |
                        <?php echo htmlspecialchars($snapshot['exam_title']); ?>
                    </small>
                    <br>
                    <small class="text-muted">
                        <?php echo date('Y-m-d H:i:s', strtotime($snapshot['timestamp'])); ?>
                    </small>

                    <?php if (file_exists($snapshot['snapshot_path'])): ?>
                        <div class="mt-2">
                            <img src="<?php echo htmlspecialchars($snapshot['snapshot_path']); ?>"
                                 class="img-fluid rounded" alt="Camera snapshot"
                                 style="max-height: 200px; cursor: pointer;"
                                 onclick="showFullImage('<?php echo htmlspecialchars($snapshot['snapshot_path']); ?>')">
                        </div>
                    <?php else: ?>
                        <div class="mt-2">
                            <div class="alert alert-warning py-2">
                                <small>Image file not found</small>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="mt-2">
                        <small class="text-muted">IP: <?php echo htmlspecialchars($snapshot['ip_address']); ?></small>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<!-- Full Image Modal -->
<div class="modal fade" id="imageModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Snapshot Preview</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center">
                <img id="fullImage" src="" class="img-fluid" alt="Full size snapshot">
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function showFullImage(imagePath) {
    document.getElementById('fullImage').src = imagePath;
    var modal = new bootstrap.Modal(document.getElementById('imageModal'));
    modal.show();
}
</script>
</body>
</html>