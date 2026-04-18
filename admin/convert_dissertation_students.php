<?php
/**
 * Admin - Convert Dissertation Students to Full Students
 *
 * This page lists users who are currently marked as "dissertation_student" and allows
 * admin/staff users to convert them into full students by removing the dissertation-only role.
 * Once converted, the user will be able to access the full student portal (including finance).
 */
require_once '../includes/auth.php';
requireLogin();
requireRole(['staff', 'admin']);

$conn = getDbConnection();
$user = getCurrentUser();

$message = '';
$error = '';

// Handle password reset
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reset_password') {
    $user_id = (int)($_POST['user_id'] ?? 0);
    if ($user_id <= 0) {
        $error = 'Invalid user selected.';
    } else {
        $stmt = $conn->prepare("SELECT user_id, username FROM users WHERE user_id = ? LIMIT 1");
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $u = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$u) {
            $error = 'User not found.';
        } else {
            $new_password = 'password123';
            $hash = password_hash($new_password, PASSWORD_DEFAULT);
            $upd = $conn->prepare("UPDATE users SET password_hash = ?, must_change_password = 1 WHERE user_id = ?");
            $upd->bind_param('si', $hash, $user_id);
            if ($upd->execute()) {
                $message = "Password reset for <strong>" . htmlspecialchars($u['username']) . "</strong>. New temporary password: <code>{$new_password}</code> (user will be prompted to change on login).";
            } else {
                $error = 'Failed to reset password.';
            }
            $upd->close();
        }
    }
}

// Handle campus update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_campus') {
    $student_id = trim($_POST['student_id'] ?? '');
    $new_campus = trim($_POST['campus'] ?? '');
    $valid_campuses = ['Mzuzu Campus', 'Lilongwe Campus', 'Blantyre Campus', 'ODel Campus'];
    if (empty($student_id)) {
        $error = 'Invalid student selected.';
    } elseif (!in_array($new_campus, $valid_campuses, true)) {
        $error = 'Invalid campus selected.';
    } else {
        $upd = $conn->prepare("UPDATE students SET campus = ? WHERE student_id = ?");
        $upd->bind_param('ss', $new_campus, $student_id);
        if ($upd->execute() && $upd->affected_rows >= 0) {
            $message = "Campus updated to <strong>" . htmlspecialchars($new_campus) . "</strong> for student <strong>" . htmlspecialchars($student_id) . "</strong>.";
        } else {
            $error = 'Failed to update campus.';
        }
        $upd->close();
    }
}

// Handle conversion action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'convert_student') {
    $user_id = (int)($_POST['user_id'] ?? 0);
    if ($user_id <= 0) {
        $error = 'Invalid user selected.';
    } else {
        // Fetch user
        $stmt = $conn->prepare("SELECT user_id, username, related_student_id, additional_roles FROM users WHERE user_id = ? LIMIT 1");
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $u = $stmt->get_result()->fetch_assoc();

        if (!$u) {
            $error = 'User not found.';
        } else {
            // Remove dissertation_student role from additional_roles
            $roles = array_filter(array_map('trim', explode(',', $u['additional_roles'] ?? '')));
            $roles = array_filter($roles, function($r) {
                return $r !== 'dissertation_student';
            });
            $new_roles = implode(', ', $roles);

            $upd = $conn->prepare("UPDATE users SET additional_roles = ? WHERE user_id = ?");
            $upd->bind_param('si', $new_roles, $user_id);
            if ($upd->execute()) {
                $message = "User {$u['username']} has been converted to a full student account.";

                // Ensure student_finances record exists for the related student id
                if (!empty($u['related_student_id'])) {
                    $check = $conn->prepare("SELECT finance_id FROM student_finances WHERE student_id = ? LIMIT 1");
                    $check->bind_param('s', $u['related_student_id']);
                    $check->execute();
                    if ($check->get_result()->num_rows === 0) {
                        // Create a minimal record so finance pages can function
                        $stmt = $conn->prepare("INSERT INTO student_finances (student_id, expected_total, expected_tuition, total_paid, balance, payment_percentage, content_access_weeks) VALUES (?, 0, 0, 0, 0, 0, 0)");
                        $stmt->bind_param('s', $u['related_student_id']);
                        $stmt->execute();
                    }
                }
            } else {
                $error = 'Failed to update user roles.';
            }
        }
    }
}

// Load dissertation student users
$rows = [];
$result = $conn->query("SELECT u.user_id, u.username, u.email, u.related_student_id, u.additional_roles, s.full_name, s.campus FROM users u LEFT JOIN students s ON u.related_student_id = s.student_id WHERE u.additional_roles LIKE '%dissertation_student%' ORDER BY u.user_id DESC");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
}

$page_title = 'Convert Dissertation Students';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title) ?> - VLE</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
</head>
<body>
<?php include 'header_nav.php'; ?>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold mb-0"><i class="bi bi-person-badge me-2"></i><?= htmlspecialchars($page_title) ?></h3>
            <small class="text-muted">Convert dissertation-only accounts into full student accounts (enables full student portal / finance access).</small>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle me-2"></i><?= $message ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-x-circle me-2"></i><?= htmlspecialchars($error) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>

    <div class="card shadow-sm">
        <div class="card-body p-0">
            <?php if (empty($rows)): ?>
                <div class="p-4 text-center text-muted">
                    <i class="bi bi-person-check display-4 mb-3"></i>
                    <p class="mb-0">No dissertation-only user accounts found.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Username</th>
                                <th>Email</th>
                                <th>Student ID</th>
                                <th>Name</th>
                                <th>Campus</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $row): ?>
                                <tr>
                                    <td><?= htmlspecialchars($row['username']) ?></td>
                                    <td><?= htmlspecialchars($row['email']) ?></td>
                                    <td><?= htmlspecialchars($row['related_student_id']) ?></td>
                                    <td><?= htmlspecialchars($row['full_name'] ?? '') ?></td>
                                    <td>
                                        <?php if (!empty($row['related_student_id'])): ?>
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="action" value="update_campus">
                                            <input type="hidden" name="student_id" value="<?= htmlspecialchars($row['related_student_id']) ?>">
                                            <select name="campus" class="form-select form-select-sm d-inline-block" style="width:auto;min-width:150px;" onchange="this.form.submit()">
                                                <?php
                                                $campus_options = ['Mzuzu Campus', 'Lilongwe Campus', 'Blantyre Campus', 'ODel Campus'];
                                                $current_campus = $row['campus'] ?? '';
                                                foreach ($campus_options as $opt):
                                                ?>
                                                <option value="<?= htmlspecialchars($opt) ?>" <?= ($current_campus === $opt) ? 'selected' : '' ?>><?= htmlspecialchars($opt) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </form>
                                        <?php else: ?>
                                        <span class="text-muted">N/A</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="d-flex gap-1 flex-wrap">
                                            <form method="POST" class="d-inline">
                                                <input type="hidden" name="action" value="reset_password">
                                                <input type="hidden" name="user_id" value="<?= (int)$row['user_id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-warning" onclick="return confirm('Reset password for <?= htmlspecialchars($row['username'], ENT_QUOTES) ?>? They will get a temporary password.');">
                                                    <i class="bi bi-key me-1"></i>Reset Pass
                                                </button>
                                            </form>
                                            <form method="POST" class="d-inline">
                                                <input type="hidden" name="action" value="convert_student">
                                                <input type="hidden" name="user_id" value="<?= (int)$row['user_id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-success" onclick="return confirm('Convert this dissertation student to a full student account? This will remove their dissertation-only role.');">
                                                    <i class="bi bi-arrow-repeat me-1"></i>Convert
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

</body>
</html>
