<?php
require_once __DIR__ . '/../includes/ui.php';
dmsRequireRole(['student']);

$conn = dmsGetDbConnection();
$user = dmsCurrentUser();
$uid = (int)$user['user_id'];

function getFeeSummary(mysqli $conn, int $uid): ?array {
    $sql = 'SELECT f.* FROM dissertation_fees f JOIN dissertations d ON f.dissertation_id = d.dissertation_id WHERE d.student_user_id = ? LIMIT 1';
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
}

function studentPhaseLocked(?array $fee, string $phase): bool {
    if (!$fee) {
        return false;
    }

    $installment = (float)$fee['installment_amount'];
    $totalPaid = (float)$fee['total_paid'];

    if ($phase === 'proposal' && (int)$fee['lock_before_proposal'] === 1 && $totalPaid < $installment) {
        return true;
    }

    if ($phase === 'ethics' && (int)$fee['lock_before_ethics'] === 1 && $totalPaid < ($installment * 2)) {
        return true;
    }

    if ($phase === 'final_submission' && (int)$fee['lock_before_final'] === 1 && (float)$fee['balance'] > 0) {
        return true;
    }

    return false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'submit_topic') {
        $title = trim($_POST['title'] ?? '');
        $topicArea = trim($_POST['topic_area'] ?? '');
        $text = trim($_POST['submission_text'] ?? '');

        if ($title === '' || $topicArea === '') {
            $_SESSION['dms_flash_error'] = 'Topic title and area are required.';
        } else {
            $check = $conn->prepare('SELECT dissertation_id FROM dissertations WHERE student_user_id = ? LIMIT 1');
            $check->bind_param('i', $uid);
            $check->execute();
            $existing = $check->get_result()->fetch_assoc();

            if ($existing) {
                $_SESSION['dms_flash_error'] = 'You already have a dissertation record.';
            } else {
                $coordinator = $conn->query("SELECT user_id FROM users WHERE role = 'research_coordinator' ORDER BY user_id LIMIT 1")->fetch_assoc();
                $coordId = (int)($coordinator['user_id'] ?? 0);
                $insert = $conn->prepare('INSERT INTO dissertations (student_user_id, title, topic_area, status, current_phase, coordinator_user_id) VALUES (?, ?, ?, \"topic_submission\", \"topic\", ?)');
                $insert->bind_param('issi', $uid, $title, $topicArea, $coordId);
                if ($insert->execute()) {
                    $did = (int)$conn->insert_id;
                    $sub = $conn->prepare('INSERT INTO dissertation_submissions (dissertation_id, phase, version, submission_text, status) VALUES (?, \"topic\", 1, ?, \"submitted\")');
                    $sub->bind_param('is', $did, $text);
                    $sub->execute();
                    $fee = $conn->prepare('INSERT INTO dissertation_fees (dissertation_id, student_user_id) VALUES (?, ?)');
                    $fee->bind_param('ii', $did, $uid);
                    $fee->execute();
                    $_SESSION['dms_flash_success'] = 'Topic submitted successfully.';
                } else {
                    $_SESSION['dms_flash_error'] = 'Failed to submit topic.';
                }
            }
        }
    }

    if ($action === 'submit_phase') {
        $did = (int)($_POST['dissertation_id'] ?? 0);
        $phase = $_POST['phase'] ?? '';
        $text = trim($_POST['submission_text'] ?? '');
        $allowed = ['chapter1', 'chapter2', 'proposal', 'ethics', 'final_submission'];

        if (!in_array($phase, $allowed, true) || $text === '') {
            $_SESSION['dms_flash_error'] = 'Provide valid phase and content.';
        } else {
            $fee = getFeeSummary($conn, $uid);
            if (studentPhaseLocked($fee, $phase)) {
                $_SESSION['dms_flash_error'] = 'This stage is locked until required dissertation fee payment is completed.';
            } else {
                $own = $conn->prepare('SELECT dissertation_id FROM dissertations WHERE dissertation_id = ? AND student_user_id = ? LIMIT 1');
                $own->bind_param('ii', $did, $uid);
                $own->execute();
                if (!$own->get_result()->fetch_assoc()) {
                    $_SESSION['dms_flash_error'] = 'Invalid dissertation record.';
                } else {
                    $versionSql = 'SELECT COALESCE(MAX(version), 0) + 1 next_v FROM dissertation_submissions WHERE dissertation_id = ? AND phase = ?';
                    $verStmt = $conn->prepare($versionSql);
                    $verStmt->bind_param('is', $did, $phase);
                    $verStmt->execute();
                    $v = (int)$verStmt->get_result()->fetch_assoc()['next_v'];

                    $insert = $conn->prepare('INSERT INTO dissertation_submissions (dissertation_id, phase, version, submission_text, status) VALUES (?, ?, ?, ?, \"submitted\")');
                    $insert->bind_param('isis', $did, $phase, $v, $text);

                    if ($insert->execute()) {
                        $statusMap = [
                            'chapter1' => 'chapter1_submitted',
                            'chapter2' => 'chapter2_submitted',
                            'proposal' => 'proposal_submitted',
                            'ethics' => 'ethics_submitted',
                            'final_submission' => 'final_submitted'
                        ];
                        $status = $statusMap[$phase] ?? 'topic_submission';
                        $update = $conn->prepare('UPDATE dissertations SET status = ?, current_phase = ?, updated_at = NOW() WHERE dissertation_id = ?');
                        $update->bind_param('ssi', $status, $phase, $did);
                        $update->execute();
                        $_SESSION['dms_flash_success'] = 'Submission saved for ' . $phase . ' (version ' . $v . ').';
                    } else {
                        $_SESSION['dms_flash_error'] = 'Failed to save submission.';
                    }
                }
            }
        }
    }

    header('Location: ' . dmsBaseUrl() . '/student/dashboard.php');
    exit;
}

$student = null;
$studentStmt = $conn->prepare('SELECT * FROM students WHERE user_id = ? LIMIT 1');
$studentStmt->bind_param('i', $uid);
$studentStmt->execute();
$student = $studentStmt->get_result()->fetch_assoc();

$diss = null;
$dissStmt = $conn->prepare('SELECT * FROM dissertations WHERE student_user_id = ? LIMIT 1');
$dissStmt->bind_param('i', $uid);
$dissStmt->execute();
$diss = $dissStmt->get_result()->fetch_assoc();

$submissions = [];
if ($diss) {
    $subStmt = $conn->prepare('SELECT * FROM dissertation_submissions WHERE dissertation_id = ? ORDER BY submitted_at DESC');
    $did = (int)$diss['dissertation_id'];
    $subStmt->bind_param('i', $did);
    $subStmt->execute();
    $submissions = $subStmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

$fee = getFeeSummary($conn, $uid);

dmsRenderPageStart('Student Dissertation Portal', $user);
dmsFlashMessage();
?>
<div class="card">
    <h3>Student Profile</h3>
    <?php if ($student): ?>
        <p><strong>ID:</strong> <?= htmlspecialchars($student['student_id']) ?> | <strong>Program:</strong> <?= htmlspecialchars((string)$student['program']) ?> | <strong>Year:</strong> <?= (int)$student['year_of_study'] ?></p>
    <?php else: ?>
        <p>No student profile found. Ask admin to create one for your account.</p>
    <?php endif; ?>
</div>

<div class="grid">
    <div class="card">
        <h3>Dissertation Fee</h3>
        <?php if ($fee): ?>
            <p><strong>Total:</strong> MK <?= number_format((float)$fee['total_fee'], 2) ?></p>
            <p><strong>Paid:</strong> MK <?= number_format((float)$fee['total_paid'], 2) ?></p>
            <p><strong>Balance:</strong> MK <?= number_format((float)$fee['balance'], 2) ?></p>
            <p class="muted">Installment 1 required for proposal, installment 2 for ethics, full payment for final submission.</p>
        <?php else: ?>
            <p>No dissertation fee record yet. It is created with your dissertation topic submission.</p>
        <?php endif; ?>
    </div>

    <?php if (!$diss): ?>
    <div class="card">
        <h3>Submit Dissertation Topic</h3>
        <form method="POST">
            <input type="hidden" name="action" value="submit_topic">
            <label>Title</label>
            <input type="text" name="title" required>
            <label>Topic Area</label>
            <input type="text" name="topic_area" required>
            <label>Concept Note Summary</label>
            <textarea name="submission_text" rows="6" required></textarea>
            <button class="btn" type="submit">Submit Topic</button>
        </form>
    </div>
    <?php else: ?>
    <div class="card">
        <h3>Submit Dissertation Phase</h3>
        <p><strong>Current Status:</strong> <?= htmlspecialchars($diss['status']) ?> | <strong>Current Phase:</strong> <?= htmlspecialchars($diss['current_phase']) ?></p>
        <form method="POST">
            <input type="hidden" name="action" value="submit_phase">
            <input type="hidden" name="dissertation_id" value="<?= (int)$diss['dissertation_id'] ?>">
            <label>Phase</label>
            <select name="phase" required>
                <option value="chapter1">chapter1</option>
                <option value="chapter2">chapter2</option>
                <option value="proposal">proposal</option>
                <option value="ethics">ethics</option>
                <option value="final_submission">final_submission</option>
            </select>
            <label>Submission text</label>
            <textarea name="submission_text" rows="6" required></textarea>
            <button class="btn" type="submit">Submit Phase</button>
        </form>
    </div>
    <?php endif; ?>
</div>

<?php if ($diss): ?>
<div class="card">
    <h3>Submission History</h3>
    <table>
        <tr><th>Phase</th><th>Version</th><th>Status</th><th>Submitted</th></tr>
        <?php foreach ($submissions as $s): ?>
            <tr>
                <td><?= htmlspecialchars($s['phase']) ?></td>
                <td><?= (int)$s['version'] ?></td>
                <td><?= htmlspecialchars($s['status']) ?></td>
                <td><?= htmlspecialchars($s['submitted_at']) ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
</div>
<?php endif; ?>
<?php dmsRenderPageEnd(); ?>
