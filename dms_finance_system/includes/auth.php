<?php
require_once __DIR__ . '/config.php';

function dmsLogin(string $usernameOrEmail, string $password): array {
    $conn = dmsGetDbConnection();
    $stmt = $conn->prepare('SELECT * FROM users WHERE username = ? OR email = ? LIMIT 1');
    $stmt->bind_param('ss', $usernameOrEmail, $usernameOrEmail);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        if ((int)$row['is_active'] !== 1) {
            return ['success' => false, 'message' => 'Your account is inactive.'];
        }

        if (password_verify($password, $row['password_hash'])) {
            $_SESSION['dms_user_id'] = (int)$row['user_id'];
            $_SESSION['dms_role'] = $row['role'];
            $_SESSION['dms_last_activity'] = time();
            return ['success' => true];
        }
    }

    return ['success' => false, 'message' => 'Invalid login credentials.'];
}

function dmsLogout(): void {
    session_unset();
    session_destroy();
}

function dmsIsLoggedIn(): bool {
    if (empty($_SESSION['dms_user_id'])) {
        return false;
    }

    if (!empty($_SESSION['dms_last_activity'])) {
        $elapsed = time() - (int)$_SESSION['dms_last_activity'];
        if ($elapsed > DMS_SESSION_TIMEOUT) {
            dmsLogout();
            return false;
        }
    }

    $_SESSION['dms_last_activity'] = time();
    return true;
}

function dmsCurrentUser(): ?array {
    if (!dmsIsLoggedIn()) {
        return null;
    }

    $conn = dmsGetDbConnection();
    $stmt = $conn->prepare('SELECT user_id, full_name, username, email, role FROM users WHERE user_id = ? LIMIT 1');
    $uid = (int)$_SESSION['dms_user_id'];
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $result = $stmt->get_result();

    return $result->fetch_assoc() ?: null;
}

function dmsRoleDashboard(string $role): string {
    switch ($role) {
        case 'admin': return 'admin/dashboard.php';
        case 'research_coordinator': return 'coordinator/dashboard.php';
        case 'supervisor': return 'supervisor/dashboard.php';
        case 'finance_officer': return 'finance/dashboard.php';
        case 'student': return 'student/dashboard.php';
        default: return 'login.php';
    }
}

function dmsRequireLogin(): void {
    if (!dmsIsLoggedIn()) {
        header('Location: ' . dmsBaseUrl() . '/login.php');
        exit;
    }
}

function dmsRequireRole(array $roles): void {
    dmsRequireLogin();

    $role = $_SESSION['dms_role'] ?? '';
    if (!in_array($role, $roles, true)) {
        $redirect = dmsRoleDashboard($role);
        header('Location: ' . dmsBaseUrl() . '/' . $redirect);
        exit;
    }
}
