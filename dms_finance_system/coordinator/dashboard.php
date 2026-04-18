<?php
require_once __DIR__ . '/../includes/ui.php';
dmsRequireRole(['research_coordinator', 'admin']);

$conn = dmsGetDbConnection();
$user = dmsCurrentUser();
$uid = (int)$user['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $did = (int)($_POST['dissertation_id'] ?? 0);

    if ($action === 'assign_supervisor') {
        $supervisorId = (int)($_POST['supervisor_user_id'] ?? 0);
        $stmt = $conn->prepare('UPDATE dissertations SET supervisor_user_id = ?, coordinator_user_id = ?, updated_at = NOW() WHERE dissertation_id = ?');
        $stmt->bind_param('iii', $supervisorId, $uid, $did);
        if ($stmt->execute()) {
            $_SESSION['dms_flash_success'] = 'Supervisor assigned.';
        } else {
            $_SESSION['dms_flash_error'] = 'Failed to assign supervisor.';
        }
    }

    if ($action === 'set_status') {
        $status = $_POST['status'] ?? '';
        $allowed = ['topic_approved', 'topic_rejected', 'completed'];
        if (!in_array($status, $allowed, true)) {
            $_SESSION['dms_flash_error'] = 'Invalid status value.';
        } else {
            $stmt = $conn->prepare('UPDATE dissertations SET status = ?, updated_at = NOW() WHERE dissertation_id = ?');
            $stmt->bind_param('si', $status, $did);
            if ($stmt->execute()) {
                $fb = $conn->prepare('INSERT INTO dissertation_feedback (dissertation_id, reviewer_user_id, reviewer_role, feedback_text, feedback_type) VALUES (?, ?, \"research_coordinator\", ?, ?)');
                $text = trim($_POST['feedback_text'] ?? 'Status updated by coordinator.');
                $type = $status === 'topic_rejected' ? 'rejection' : 'approval';
                $fb->bind_param('iiss', $did, $uid, $text, $type);
                $fb->execute();
                $_SESSION['dms_flash_success'] = 'Dissertation status updated.';
            } else {
                $_SESSION['dms_flash_error'] = 'Could not update dissertation status.';
            }
        }
    }

    header('Location: ' . dmsBaseUrl() . '/coordinator/dashboard.php');
    exit;
}

$supervisors = $conn->query("SELECT user_id, full_name FROM users WHERE role = 'supervisor' AND is_active = 1 ORDER BY full_name")->fetch_all(MYSQLI_ASSOC);

$sql = "SELECT d.*, s.full_name student_name, s.email student_email, sup.full_name supervisor_name
        FROM dissertations d
        JOIN users s ON d.student_user_id = s.user_id
        LEFT JOIN users sup ON d.supervisor_user_id = sup.user_id
        ORDER BY d.updated_at DESC";
$rows = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);

dmsRenderPageStart('Research Coordinator Dashboard', $user);
dmsFlashMessage();
?>
<div class="card">
    <h3>Dissertation Queue</h3>
    <table>
        <tr><th>Student</th><th>Title</th><th>Status</th><th>Phase</th><th>Supervisor</th><th>Actions</th></tr>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><?= htmlspecialchars($r['student_name']) ?><br><span class="muted"><?= htmlspecialchars($r['student_email']) ?></span></td>
                <td><?= htmlspecialchars((string)$r['title']) ?></td>
                <td><span class="badge"><?= htmlspecialchars($r['status']) ?></span></td>
                <td><?= htmlspecialchars($r['current_phase']) ?></td>
                <td><?= htmlspecialchars((string)($r['supervisor_name'] ?: 'Unassigned')) ?></td>
                <td>
                    <form class="inline" method="POST" style="margin-bottom:8px;">
                        <input type="hidden" name="action" value="assign_supervisor">
                        <input type="hidden" name="dissertation_id" value="<?= (int)$r['dissertation_id'] ?>">
                        <select name="supervisor_user_id" required>
                            <option value="">Select supervisor</option>
                            <?php foreach ($supervisors as $s): ?>
                                <option value="<?= (int)$s['user_id'] ?>"><?= htmlspecialchars($s['full_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button class="btn secondary" type="submit">Assign</button>
                    </form>

                    <form method="POST">
                        <input type="hidden" name="action" value="set_status">
                        <input type="hidden" name="dissertation_id" value="<?= (int)$r['dissertation_id'] ?>">
                        <select name="status" required>
                            <option value="topic_approved">topic_approved</option>
                            <option value="topic_rejected">topic_rejected</option>
                            <option value="completed">completed</option>
                        </select>
                        <input type="text" name="feedback_text" placeholder="Optional feedback text">
                        <button class="btn warn" type="submit">Update Status</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>
</div>
<?php dmsRenderPageEnd(); ?>
