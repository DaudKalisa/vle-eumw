<?php
require_once __DIR__ . '/auth.php';

function dmsRenderPageStart(string $title, ?array $user = null): void {
    $base = dmsBaseUrl();
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">';
    echo '<title>' . htmlspecialchars($title) . '</title>';
    echo '<link rel="stylesheet" href="' . $base . '/assets/style.css">';
    echo '</head><body><div class="container">';
    echo '<header class="topbar"><h1>DMS + Finance</h1>';

    if ($user) {
        echo '<div class="userbox">';
        echo '<span>' . htmlspecialchars($user['full_name']) . ' (' . htmlspecialchars($user['role']) . ')</span>';
        echo '<a class="btn secondary" href="' . $base . '/logout.php">Logout</a>';
        echo '</div>';
    }

    echo '</header>';
}

function dmsRenderPageEnd(): void {
    echo '</div></body></html>';
}

function dmsFlashMessage(): void {
    if (!empty($_SESSION['dms_flash_success'])) {
        echo '<div class="alert success">' . htmlspecialchars($_SESSION['dms_flash_success']) . '</div>';
        unset($_SESSION['dms_flash_success']);
    }
    if (!empty($_SESSION['dms_flash_error'])) {
        echo '<div class="alert error">' . htmlspecialchars($_SESSION['dms_flash_error']) . '</div>';
        unset($_SESSION['dms_flash_error']);
    }
}
