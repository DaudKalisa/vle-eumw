<?php
require_once __DIR__ . '/../includes/ui.php';
dmsRequireRole(['supervisor']);

$conn = dmsGetDbConnection();
$user = dmsCurrentUser();
$uid = (int)$user['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'review_submission') {
        $submissionId = (int)($_POST['submission_id'] ?? 0);
        $status = $_POST['status'] ?? '';
        $feedback = trim($_POST['feedback_text'] ?? '');

        $allowed = ['approved', 'revision_requested', 'rejected'];
        if (!in_array($status, $allowed, true) || $feedback === '') {
            $_SESSION['dms_flash_error'] = 'Provide valid status and feedback.';
        } else {
            $get = $conn->prepare('SELECT s.submission_id, s.dissertation_id FROM dissertation_submissions s JOIN dissertations d ON s.dissertation_id = d.dissertation_id WHERE s.submission_id = ? AND d.supervisor_user_id = ? LIMIT 1');
            $get->bind_param('ii', $submissionId, $uid);
            $get->execute();
            $row = $get->get_result()->fetch_assoc();

            if (!$row) {
                $_SESSION['dms_flash_error'] = 'Submission not found for your supervision list.';
            } else {
                $upd = $conn->prepare('UPDATE dissertation_submissions SET status = ?, reviewed_by = ?, reviewed_at = NOW() WHERE submission_id = ?');
                $upd->bind_param('sii', $status, $uid, $submissionId);
                $upd->execute();

                $type = $status === 'approved' ? 'approval' : ($status === 'rejected' ? 'rejection' : 'revision_request');
                $fb = $conn->prepare('INSERT INTO dissertation_feedback (dissertation_id, submission_id, reviewer_user_id, reviewer_role, feedback_text, feedback_type) VALUES (?, ?, ?, \"supervisor\", ?, ?)');
                $did = (int)$row['dissertation_id'];
                $fb->bind_param('iiiss', $did, $submissionId, $uid, $feedback, $type);
                $fb->execute();

                $_SESSION['dms_flash_success'] = 'Submission reviewed successfully.';
            }
        }
    }

    header('Location: ' . dmsBaseUrl() . '/supervisor/dashboard.php');
    exit;
}

$sql = "SELECT d.dissertation_id, d.title, d.status, s.full_name student_name, sub.submission_id, sub.phase, sub.version, sub.status submission_status, sub.submission_text, sub.submitted_at
        FROM dissertations d
        JOIN users s ON d.student_user_id = s.user_id
        LEFT JOIN dissertation_submissions sub ON d.dissertation_id = sub.dissertation_id
        WHERE d.supervisor_user_id = ?
        ORDER BY sub.submitted_at DESC";
$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $uid);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

dmsRenderPageStart('Supervisor Dashboard', $user);
dmsFlashMessage();
?>
<div class="card">
    <h3>Assigned Dissertation Submissions</h3>
    <table>
        <tr><th>Student</th><th>Title</th><th>Phase</th><th>Version</th><th>Submission</th><th>Review</th></tr>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><?= htmlspecialchars((string)$r['student_name']) ?></td>
                <td><?= htmlspecialchars((string)$r['title']) ?><br><span class="muted">Overall status: <?= htmlspecialchars((string)$r['status']) ?></span></td>
                <td><?= htmlspecialchars((string)$r['phase']) ?></td>
                <td><?= (int)$r['version'] ?></td>
                <td>
                    <span class="badge"><?= htmlspecialchars((string)$r['submission_status']) ?></span><br>
                    <small><?= nl2br(htmlspecialchars(substr((string)$r['submission_text'], 0, 280))) ?></small>
                </td>
                <td>
                    <?php if (!empty($r['submission_id'])): ?>
                    <form method="POST">
                        <input type="hidden" name="action" value="review_submission">
                        <input type="hidden" name="submission_id" value="<?= (int)$r['submission_id'] ?>">
                        <select name="status" required>
                            <option value="approved">approved</option>
                            <option value="revision_requested">revision_requested</option>
                            <option value="rejected">rejected</option>
                        </select>
                        <textarea name="feedback_text" rows="4" placeholder="Write feedback" required></textarea>
                        <button class="btn" type="submit">Submit Review</button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>
</div>
<?php dmsRenderPageEnd(); ?>
