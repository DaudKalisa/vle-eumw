<?php
/**
 * Librarian – Graduation Clearance Step
 * Check outstanding books and clear the student
 */
require_once '../includes/auth.php';
requireLogin();
requireRole(['staff', 'admin', 'super_admin']);

$conn = getDbConnection();
$user = getCurrentUser();
$success = '';
$error = '';

// Ensure library details table
$conn->query("CREATE TABLE IF NOT EXISTS graduation_library_details (
    id INT AUTO_INCREMENT PRIMARY KEY,
    application_id INT NOT NULL UNIQUE,
    has_outstanding_books TINYINT(1) DEFAULT 0,
    books_list TEXT DEFAULT NULL,
    cleared TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $app_id   = (int)$_POST['app_id'];
    $decision = $_POST['decision'] ?? '';
    $notes    = trim($_POST['notes'] ?? '');
    $has_books = isset($_POST['has_outstanding_books']) ? 1 : 0;
    $books_list = trim($_POST['books_list'] ?? '');

    $stmt = $conn->prepare("SELECT * FROM graduation_applications WHERE application_id = ? AND current_step = 'librarian'");
    $stmt->bind_param("i", $app_id);
    $stmt->execute();
    $app = $stmt->get_result()->fetch_assoc();

    if (!$app) {
        $error = 'Application not found or not at Library step.';
    } elseif (!in_array($decision, ['approved', 'rejected'])) {
        $error = 'Invalid decision.';
    } else {
        $cleared = ($decision === 'approved') ? 1 : 0;
        $conn->query("DELETE FROM graduation_library_details WHERE application_id = $app_id");
        $stmt = $conn->prepare("INSERT INTO graduation_library_details (application_id, has_outstanding_books, books_list, cleared) VALUES (?,?,?,?)");
        $stmt->bind_param("iisi", $app_id, $has_books, $books_list, $cleared);
        $stmt->execute();

        $uid = (int)$user['user_id'];
        $officer_name = $user['display_name'] ?? $user['username'];
        $stmt = $conn->prepare("UPDATE graduation_clearance_steps SET status=?, officer_user_id=?, officer_name=?, officer_role='librarian', signature_text=?, notes=?, actioned_at=NOW() WHERE application_id=? AND step_name='librarian'");
        $stmt->bind_param("sisssi", $decision, $uid, $officer_name, $officer_name, $notes, $app_id);
        $stmt->execute();

        if ($decision === 'approved') {
            $conn->query("UPDATE graduation_applications SET status='librarian_approved', current_step='admin' WHERE application_id=$app_id");
            $success = "Library clearance approved — forwarded to Admin for transcript generation.";
        } else {
            $conn->query("UPDATE graduation_applications SET status='rejected', rejection_reason='" . $conn->real_escape_string($notes) . "' WHERE application_id=$app_id");
            $success = "Application #$app_id rejected.";
        }
    }
}

$pending = [];
$rs = $conn->query("SELECT ga.* FROM graduation_applications ga WHERE ga.current_step = 'librarian' ORDER BY ga.submitted_at ASC");
if ($rs) while ($r = $rs->fetch_assoc()) $pending[] = $r;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Library Clearance – Graduation</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body{font-family:'Inter',sans-serif;background:#f0f4f8;}
        .top-bar{background:#fff;border-bottom:1px solid #e2e8f0;padding:.75rem 1.5rem;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:100;}
        .page-header{background:linear-gradient(135deg,#0891b2,#0e7490);color:#fff;border-radius:16px;padding:1.5rem 2rem;margin-bottom:1.5rem;}
        .app-card{background:#fff;border-radius:12px;border:1px solid #e2e8f0;padding:1.25rem;margin-bottom:1rem;}
    </style>
</head>
<body>
<div class="top-bar">
    <a href="../admin/dashboard.php" style="font-weight:700;color:#0891b2;text-decoration:none;"><i class="bi bi-arrow-left me-2"></i>Dashboard</a>
    <span class="badge" style="background:#0891b2;color:#fff;"><?= count($pending) ?> Pending</span>
</div>
<div class="container-fluid py-4" style="max-width:1000px;">
    <div class="page-header">
        <h4 class="mb-0"><i class="bi bi-book me-2"></i>Library – Graduation Clearance</h4>
        <p class="mb-0 opacity-75 mt-1">Check for outstanding library books before clearing the student</p>
    </div>

    <?php if ($success): ?><div class="alert alert-success"><?= $success ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <?php if (empty($pending)): ?>
    <div class="text-center py-5 text-muted"><i class="bi bi-check-circle fs-1 d-block mb-2"></i>No pending library clearances.</div>
    <?php else: foreach ($pending as $app): $aid = (int)$app['application_id']; ?>
    <div class="app-card">
        <h5 class="mb-1"><?= htmlspecialchars($app['first_name'] . ' ' . ($app['middle_name'] ?? '') . ' ' . $app['last_name']) ?></h5>
        <div class="small text-muted mb-3">
            #<?= $aid ?> | <?= htmlspecialchars($app['campus']) ?> | <?= htmlspecialchars($app['program']) ?>
        </div>

        <form method="post">
            <input type="hidden" name="app_id" value="<?= $aid ?>">
            <div class="row g-3 mb-3">
                <div class="col-md-4">
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" name="has_outstanding_books" id="books_<?= $aid ?>" onchange="document.getElementById('bl_<?= $aid ?>').style.display=this.checked?'block':'none'">
                        <label class="form-check-label" for="books_<?= $aid ?>">Has Outstanding Books</label>
                    </div>
                </div>
                <div class="col-md-8" id="bl_<?= $aid ?>" style="display:none;">
                    <textarea name="books_list" class="form-control form-control-sm" rows="2" placeholder="List outstanding books..."></textarea>
                </div>
            </div>
            <div class="row g-3">
                <div class="col-md-4">
                    <select name="decision" class="form-select form-select-sm" required>
                        <option value="">Decision...</option>
                        <option value="approved">Cleared – No Outstanding Books</option>
                        <option value="rejected">Not Cleared – Books Outstanding</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <input type="text" name="notes" class="form-control form-control-sm" placeholder="Librarian notes">
                </div>
                <div class="col-md-4">
                    <button type="submit" class="btn btn-sm" style="background:#0891b2;color:#fff;"><i class="bi bi-check-lg me-1"></i>Submit</button>
                </div>
            </div>
        </form>
    </div>
    <?php endforeach; endif; ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
