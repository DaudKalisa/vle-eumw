<?php
/**
 * Exam Tokens Management - Examination Officer
 */
require_once '../includes/auth.php';
requireLogin();
requireRole(['staff', 'examination_manager']);

$conn = getDbConnection();
$user = getCurrentUser();
$success_message = '';
$error_message = '';

$exam_id = (int)($_GET['exam_id'] ?? $_POST['exam_id'] ?? 0);

// Get exam
$exam = null;
if ($exam_id) {
    $stmt = $conn->prepare("SELECT e.*, c.course_name, c.course_code FROM exams e LEFT JOIN vle_courses c ON e.course_id = c.course_id WHERE e.exam_id = ?");
    $stmt->bind_param("i", $exam_id);
    $stmt->execute();
    $exam = $stmt->get_result()->fetch_assoc();
}

// Generate tokens
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_tokens'])) {
    $gen_exam_id = (int)$_POST['exam_id'];
    $count = min((int)$_POST['token_count'], 500);
    $token_type = $_POST['token_type'] ?? 'single_use';
    $expires_at = !empty($_POST['expires_at']) ? $_POST['expires_at'] : null;
    $created_by = $_SESSION['vle_user_id'];
    
    $generated = 0;
    // Ensure student_id allows NULL (older schema had it NOT NULL)
    $conn->query("ALTER TABLE exam_tokens MODIFY COLUMN student_id VARCHAR(20) NULL DEFAULT NULL");
    $stmt = $conn->prepare("INSERT INTO exam_tokens (exam_id, token, token_type, expires_at, created_by) VALUES (?, ?, ?, ?, ?)");
    
    for ($i = 0; $i < $count; $i++) {
        $token = strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 8));
        $token = substr($token, 0, 4) . '-' . substr($token, 4, 4);
        $stmt->bind_param("isssi", $gen_exam_id, $token, $token_type, $expires_at, $created_by);
        if ($stmt->execute()) $generated++;
    }
    
    $success_message = "$generated tokens generated successfully.";
}

// Revoke token
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['revoke_token'])) {
    $token_id = (int)$_POST['token_id'];
    $stmt = $conn->prepare("DELETE FROM exam_tokens WHERE token_id = ? AND is_used = 0");
    $stmt->bind_param("i", $token_id);
    $stmt->execute() ? $success_message = "Token revoked." : $error_message = "Cannot revoke used token.";
}

// Revoke all unused
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['revoke_all'])) {
    $rev_exam_id = (int)$_POST['exam_id'];
    $conn->query("DELETE FROM exam_tokens WHERE exam_id = $rev_exam_id AND is_used = 0");
    $success_message = "All unused tokens revoked.";
}

// Get tokens
$filter_used = $_GET['filter'] ?? '';
$tokens = [];
if ($exam_id) {
    $where = "t.exam_id = $exam_id";
    if ($filter_used === 'used') $where .= " AND t.is_used = 1";
    elseif ($filter_used === 'unused') $where .= " AND t.is_used = 0";
    
    $result = $conn->query("
        SELECT t.*, s.full_name as used_by_name
        FROM exam_tokens t
        LEFT JOIN students s ON t.used_by = s.student_id
        WHERE $where
        ORDER BY t.created_at DESC
    ");
    if ($result) while ($row = $result->fetch_assoc()) $tokens[] = $row;
}

$all_exams = $conn->query("SELECT exam_id, exam_code, exam_name FROM exams ORDER BY created_at DESC")->fetch_all(MYSQLI_ASSOC);

$total_tokens = count($tokens);
$used_tokens = count(array_filter($tokens, fn($t) => $t['is_used']));

$page_title = "Exam Tokens";
$breadcrumbs = $exam ? [['url' => 'manage_exams.php', 'title' => 'Examinations'], ['url' => "exam_view.php?id=$exam_id", 'title' => $exam['exam_code']], ['title' => 'Tokens']] : [['title' => 'Tokens']];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Exam Tokens - VLE</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
</head>
<body>
    <?php include 'header_nav.php'; ?>

    <div class="vle-content">
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
            <div>
                <h2 class="vle-page-title"><i class="bi bi-key me-2"></i>Exam Tokens</h2>
                <?php if ($exam): ?>
                    <p class="text-muted mb-0"><?= htmlspecialchars($exam['exam_name']) ?> &mdash; <?= $total_tokens ?> tokens (<?= $used_tokens ?> used)</p>
                <?php endif; ?>
            </div>
            <div class="d-flex gap-2">
                <?php if ($exam_id): ?>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#generateModal"><i class="bi bi-plus-circle me-1"></i>Generate Tokens</button>
                    <a href="exam_view.php?id=<?= $exam_id ?>" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Back</a>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($success_message): ?>
            <div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle me-2"></i><?= $success_message ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        <?php if ($error_message): ?>
            <div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle me-2"></i><?= $error_message ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>

        <!-- Select Exam -->
        <?php if (!$exam_id): ?>
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-body">
                    <form method="GET" class="row g-2 align-items-end">
                        <div class="col-md-8">
                            <label class="form-label">Select Examination</label>
                            <select name="exam_id" class="form-select" required>
                                <option value="">-- Choose an exam --</option>
                                <?php foreach ($all_exams as $e): ?>
                                    <option value="<?= $e['exam_id'] ?>"><?= htmlspecialchars($e['exam_code'] . ' - ' . $e['exam_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4"><button type="submit" class="btn btn-primary w-100"><i class="bi bi-arrow-right me-1"></i>Load Tokens</button></div>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($exam_id): ?>
            <!-- Filter Tabs -->
            <ul class="nav nav-tabs mb-4">
                <li class="nav-item">
                    <a class="nav-link <?= $filter_used === '' ? 'active' : '' ?>" href="?exam_id=<?= $exam_id ?>">All (<?= $total_tokens ?>)</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $filter_used === 'unused' ? 'active' : '' ?>" href="?exam_id=<?= $exam_id ?>&filter=unused">Unused (<?= $total_tokens - $used_tokens ?>)</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $filter_used === 'used' ? 'active' : '' ?>" href="?exam_id=<?= $exam_id ?>&filter=used">Used (<?= $used_tokens ?>)</a>
                </li>
            </ul>

            <!-- Tokens Table -->
            <div class="card border-0 shadow-sm">
                <div class="card-body p-0">
                    <?php if (empty($tokens)): ?>
                        <div class="text-center py-5 text-muted">
                            <i class="bi bi-key display-4 d-block mb-3"></i>
                            <p>No tokens generated yet.</p>
                            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#generateModal"><i class="bi bi-plus-circle me-1"></i>Generate Tokens</button>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Token</th>
                                        <th>Type</th>
                                        <th>Status</th>
                                        <th>Used By</th>
                                        <th>Used At</th>
                                        <th>Expires</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($tokens as $t): ?>
                                    <tr>
                                        <td><code class="fs-6"><?= htmlspecialchars($t['token']) ?></code></td>
                                        <td><span class="badge bg-light text-dark"><?= ucfirst(str_replace('_', ' ', $t['token_type'])) ?></span></td>
                                        <td>
                                            <?php if ($t['is_used']): ?>
                                                <span class="badge bg-success">Used</span>
                                            <?php else: ?>
                                                <span class="badge bg-warning text-dark">Available</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= $t['used_by_name'] ? htmlspecialchars($t['used_by_name']) : '-' ?></td>
                                        <td><?= $t['used_at'] ? date('M d, h:i A', strtotime($t['used_at'])) : '-' ?></td>
                                        <td><?= $t['expires_at'] ? date('M d, Y h:i A', strtotime($t['expires_at'])) : 'Never' ?></td>
                                        <td>
                                            <?php if (!$t['is_used']): ?>
                                                <form method="POST" class="d-inline" onsubmit="return confirm('Revoke this token?')">
                                                    <input type="hidden" name="exam_id" value="<?= $exam_id ?>">
                                                    <input type="hidden" name="token_id" value="<?= $t['token_id'] ?>">
                                                    <button type="submit" name="revoke_token" class="btn btn-sm btn-outline-danger"><i class="bi bi-x-circle"></i></button>
                                                </form>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Export unused tokens -->
                        <?php $unused = array_filter($tokens, fn($t) => !$t['is_used']); ?>
                        <?php if (!empty($unused)): ?>
                        <div class="p-3 border-top">
                            <h6>Copy Unused Tokens:</h6>
                            <textarea class="form-control mb-2" rows="4" readonly onclick="this.select()"><?php echo implode("\n", array_map(fn($t) => $t['token'], $unused)); ?></textarea>
                            <div class="d-flex gap-2">
                                <form method="POST" onsubmit="return confirm('Revoke ALL unused tokens?')">
                                    <input type="hidden" name="exam_id" value="<?= $exam_id ?>">
                                    <button type="submit" name="revoke_all" class="btn btn-sm btn-danger"><i class="bi bi-x-circle me-1"></i>Revoke All Unused</button>
                                </form>
                            </div>
                        </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Generate Modal -->
    <div class="modal fade" id="generateModal" tabindex="-1">
        <div class="modal-dialog">
            <form method="POST" class="modal-content">
                <div class="modal-header bg-dark text-white">
                    <h5 class="modal-title"><i class="bi bi-key me-2"></i>Generate Tokens</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="exam_id" value="<?= $exam_id ?>">
                    <div class="mb-3">
                        <label class="form-label">Number of Tokens</label>
                        <input type="number" name="token_count" class="form-control" min="1" max="500" value="30" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Token Type</label>
                        <select name="token_type" class="form-select">
                            <option value="single_use">Single Use</option>
                            <option value="multi_use">Multi Use</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Expiry Date (optional)</label>
                        <input type="datetime-local" name="expires_at" class="form-control">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="generate_tokens" class="btn btn-primary"><i class="bi bi-key me-1"></i>Generate</button>
                </div>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
