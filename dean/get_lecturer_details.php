<?php
/**
 * Dean Portal - Get Lecturer Details (AJAX)
 * Returns HTML for lecturer details modal
 */

require_once '../includes/auth.php';
requireLogin();
requireRole(['dean', 'admin']);

$conn = getDbConnection();
$lecturer_id = (int)($_GET['id'] ?? 0);

if ($lecturer_id <= 0) {
    echo '<div class="alert alert-danger">Invalid lecturer ID</div>';
    exit;
}

// Get lecturer details
$stmt = $conn->prepare("SELECT * FROM lecturers WHERE lecturer_id = ?");
$stmt->bind_param("i", $lecturer_id);
$stmt->execute();
$result = $stmt->get_result();
$lecturer = $result->fetch_assoc();

if (!$lecturer) {
    echo '<div class="alert alert-danger">Lecturer not found</div>';
    exit;
}

// Get courses
$courses = [];
$stmt = $conn->prepare("SELECT * FROM vle_courses WHERE lecturer_id = ?");
$stmt->bind_param("i", $lecturer_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $courses[] = $row;
}

// Get claims summary
$claims_result = $conn->prepare("
    SELECT COUNT(*) as total_claims, 
           SUM(CASE WHEN status = 'paid' THEN total_amount ELSE 0 END) as paid_amount,
           SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_claims
    FROM lecturer_finance_requests 
    WHERE lecturer_id = ?
");
$claims_result->bind_param("s", $lecturer['lecturer_id']);
$claims_result->execute();
$claims = $claims_result->get_result()->fetch_assoc();
?>

<div class="row mb-4">
    <div class="col-md-4 text-center">
        <?php if (!empty($lecturer['profile_picture']) && file_exists('../uploads/profiles/' . $lecturer['profile_picture'])): ?>
            <img src="../uploads/profiles/<?= htmlspecialchars($lecturer['profile_picture']) ?>" class="rounded-circle mb-3" style="width: 120px; height: 120px; object-fit: cover;">
        <?php else: ?>
            <div class="rounded-circle bg-primary bg-opacity-10 text-primary d-inline-flex align-items-center justify-content-center mb-3" style="width: 120px; height: 120px; font-size: 3rem; font-weight: 700;">
                <?= strtoupper(substr($lecturer['full_name'], 0, 1)) ?>
            </div>
        <?php endif; ?>
        <h5 class="mb-1"><?= htmlspecialchars($lecturer['full_name']) ?></h5>
        <span class="badge bg-info"><?= htmlspecialchars($lecturer['department'] ?? 'No Department') ?></span>
    </div>
    <div class="col-md-8">
        <h6 class="text-muted mb-3">Contact Information</h6>
        <table class="table table-sm">
            <tr>
                <td class="text-muted" style="width: 130px;">Email:</td>
                <td><a href="mailto:<?= htmlspecialchars($lecturer['email']) ?>"><?= htmlspecialchars($lecturer['email']) ?></a></td>
            </tr>
            <tr>
                <td class="text-muted">Phone:</td>
                <td><?= htmlspecialchars($lecturer['phone'] ?? 'N/A') ?></td>
            </tr>
            <tr>
                <td class="text-muted">Department:</td>
                <td><?= htmlspecialchars($lecturer['department'] ?? 'N/A') ?></td>
            </tr>
            <tr>
                <td class="text-muted">Qualification:</td>
                <td><?= htmlspecialchars($lecturer['qualification'] ?? 'N/A') ?></td>
            </tr>
        </table>
    </div>
</div>

<div class="row mb-4">
    <div class="col-12">
        <h6 class="text-muted mb-3">Performance Summary</h6>
        <div class="row g-3">
            <div class="col-md-3">
                <div class="p-3 bg-primary bg-opacity-10 rounded text-center">
                    <div class="fs-4 fw-bold text-primary"><?= count($courses) ?></div>
                    <small class="text-muted">Courses</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="p-3 bg-info bg-opacity-10 rounded text-center">
                    <div class="fs-4 fw-bold text-info"><?= $claims['total_claims'] ?? 0 ?></div>
                    <small class="text-muted">Total Claims</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="p-3 bg-warning bg-opacity-10 rounded text-center">
                    <div class="fs-4 fw-bold text-warning"><?= $claims['pending_claims'] ?? 0 ?></div>
                    <small class="text-muted">Pending</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="p-3 bg-success bg-opacity-10 rounded text-center">
                    <div class="fs-5 fw-bold text-success">MKW <?= number_format($claims['paid_amount'] ?? 0) ?></div>
                    <small class="text-muted">Total Paid</small>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if (!empty($courses)): ?>
<div class="row">
    <div class="col-12">
        <h6 class="text-muted mb-3">Assigned Courses</h6>
        <div class="table-responsive">
            <table class="table table-sm table-bordered">
                <thead class="table-light">
                    <tr>
                        <th>Code</th>
                        <th>Course Name</th>
                        <th>Program</th>
                        <th>Year/Sem</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($courses as $course): ?>
                    <tr>
                        <td><code><?= htmlspecialchars($course['course_code']) ?></code></td>
                        <td><?= htmlspecialchars($course['course_name']) ?></td>
                        <td><?= htmlspecialchars($course['program_of_study'] ?? 'N/A') ?></td>
                        <td><?php
                            $yrs = [$course['year_of_study'] ?? 1];
                            if (!empty($course['applicable_years'])) $yrs = array_merge($yrs, array_map('trim', explode(',', $course['applicable_years'])));
                            $yrs = array_unique($yrs); sort($yrs);
                            echo 'Y' . implode(',', $yrs);
                            echo '/' . ($course['semester'] === 'Both' ? 'S1&2' : 'S' . ($course['semester'] === 'Two' ? '2' : '1'));
                        ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>
