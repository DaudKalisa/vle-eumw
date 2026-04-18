<?php
require_once __DIR__ . '/../includes/ui.php';
dmsRequireRole(['admin']);

$conn = dmsGetDbConnection();
$user = dmsCurrentUser();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_user') {
    $fullName = trim($_POST['full_name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $role = $_POST['role'] ?? '';

    $allowedRoles = ['student', 'research_coordinator', 'supervisor', 'finance_officer', 'admin'];

    if ($fullName === '' || $username === '' || $email === '' || $password === '' || !in_array($role, $allowedRoles, true)) {
        $_SESSION['dms_flash_error'] = 'Please complete all fields with a valid role.';
    } else {
        $check = $conn->prepare('SELECT user_id FROM users WHERE username = ? OR email = ? LIMIT 1');
        $check->bind_param('ss', $username, $email);
        $check->execute();
        if ($check->get_result()->fetch_assoc()) {
            $_SESSION['dms_flash_error'] = 'Username or email already exists.';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $insert = $conn->prepare('INSERT INTO users (full_name, username, email, password_hash, role) VALUES (?, ?, ?, ?, ?)');
            $insert->bind_param('sssss', $fullName, $username, $email, $hash, $role);
            if ($insert->execute()) {
                $newUserId = (int)$conn->insert_id;
                if ($role === 'student') {
                    $studentId = 'DMS/' . date('Y') . '/' . str_pad((string)$newUserId, 4, '0', STR_PAD_LEFT);
                    $program = trim($_POST['program'] ?? 'Unspecified Program');
                    $year = (int)($_POST['year_of_study'] ?? 1);
                    $semester = $_POST['semester'] ?? 'One';
                    if (!in_array($semester, ['One', 'Two'], true)) {
                        $semester = 'One';
                    }
                    $student = $conn->prepare('INSERT INTO students (student_id, user_id, program, year_of_study, semester) VALUES (?, ?, ?, ?, ?)');
                    $student->bind_param('sssis', $studentId, $newUserId, $program, $year, $semester);
                    $student->execute();
                }
                $_SESSION['dms_flash_success'] = 'User created successfully.';
            } else {
                $_SESSION['dms_flash_error'] = 'Failed to create user.';
            }
        }
    }

    header('Location: ' . dmsBaseUrl() . '/admin/dashboard.php');
    exit;
}

$users = $conn->query('SELECT user_id, full_name, username, email, role, is_active, created_at FROM users ORDER BY created_at DESC')->fetch_all(MYSQLI_ASSOC);

$counts = [
    'users' => (int)$conn->query('SELECT COUNT(*) c FROM users')->fetch_assoc()['c'],
    'students' => (int)$conn->query("SELECT COUNT(*) c FROM users WHERE role = 'student'")->fetch_assoc()['c'],
    'active_diss' => (int)$conn->query("SELECT COUNT(*) c FROM dissertations WHERE status <> 'completed'")->fetch_assoc()['c'],
    'outstanding' => (float)$conn->query('SELECT COALESCE(SUM(balance),0) c FROM dissertation_fees')->fetch_assoc()['c']
];

dmsRenderPageStart('Admin Dashboard', $user);
dmsFlashMessage();
?>
<div class="grid">
    <div class="card"><div class="muted">Total Users</div><div class="stat"><?= $counts['users'] ?></div></div>
    <div class="card"><div class="muted">Students</div><div class="stat"><?= $counts['students'] ?></div></div>
    <div class="card"><div class="muted">Active Dissertations</div><div class="stat"><?= $counts['active_diss'] ?></div></div>
    <div class="card"><div class="muted">Outstanding Fees</div><div class="stat">MK <?= number_format($counts['outstanding'], 2) ?></div></div>
</div>

<div class="card">
    <h3>Create User</h3>
    <form method="POST">
        <input type="hidden" name="action" value="create_user">
        <div class="grid">
            <div><label>Full Name</label><input type="text" name="full_name" required></div>
            <div><label>Username</label><input type="text" name="username" required></div>
            <div><label>Email</label><input type="email" name="email" required></div>
            <div><label>Password</label><input type="text" name="password" required></div>
            <div>
                <label>Role</label>
                <select name="role" required>
                    <option value="student">student</option>
                    <option value="research_coordinator">research_coordinator</option>
                    <option value="supervisor">supervisor</option>
                    <option value="finance_officer">finance_officer</option>
                    <option value="admin">admin</option>
                </select>
            </div>
            <div><label>Program (students)</label><input type="text" name="program"></div>
            <div><label>Year of Study (students)</label><input type="number" name="year_of_study" min="1" max="8" value="1"></div>
            <div>
                <label>Semester (students)</label>
                <select name="semester"><option>One</option><option>Two</option></select>
            </div>
        </div>
        <button class="btn" type="submit">Create User</button>
    </form>
</div>

<div class="card">
    <h3>Users</h3>
    <p><a class="btn secondary" href="<?= dmsBaseUrl() ?>/coordinator/dashboard.php">Coordinator Panel</a> <a class="btn secondary" href="<?= dmsBaseUrl() ?>/finance/dashboard.php">Finance Panel</a></p>
    <table>
        <tr><th>Name</th><th>Username</th><th>Email</th><th>Role</th><th>Status</th><th>Created</th></tr>
        <?php foreach ($users as $u): ?>
            <tr>
                <td><?= htmlspecialchars($u['full_name']) ?></td>
                <td><?= htmlspecialchars($u['username']) ?></td>
                <td><?= htmlspecialchars($u['email']) ?></td>
                <td><span class="badge"><?= htmlspecialchars($u['role']) ?></span></td>
                <td><?= ((int)$u['is_active'] === 1) ? 'Active' : 'Inactive' ?></td>
                <td><?= htmlspecialchars($u['created_at']) ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
</div>
<?php dmsRenderPageEnd(); ?>
