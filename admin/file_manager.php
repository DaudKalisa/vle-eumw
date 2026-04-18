<?php
/**
 * System File Manager
 * Exploits University VLE
 * Admin tool for browsing, uploading, editing and managing system files
 */
require_once __DIR__ . '/../includes/auth.php';

// --- VLE Compatibility Shim ---
if (!defined('ROLE_ADMIN')) define('ROLE_ADMIN', 'admin');
if (!function_exists('isSuperAdmin')) {
    function isSuperAdmin() {
        return isset($_SESSION['vle_role']) && $_SESSION['vle_role'] === 'super_admin';
    }
}
if (!function_exists('redirect')) {
    function redirect($url) {
        header('Location: ' . SITE_URL . '/' . $url);
        exit;
    }
}
if (!class_exists('Database')) {
    class Database {
        private $connection;
        private static $instance = null;
        private function __construct() {
            $this->connection = new PDO(
                'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET,
                DB_USER, DB_PASS,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
            );
        }
        public static function getInstance() {
            if (self::$instance === null) self::$instance = new self();
            return self::$instance;
        }
        public function getConnection() { return $this->connection; }
        public function query($sql, $params = []) {
            $stmt = $this->connection->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        }
        public function lastInsertId() { return $this->connection->lastInsertId(); }
    }
}
// --- End Shim ---

requireLogin();
requireRole(['admin', 'super_admin']);

// Password protection for File Manager
define('FILE_MANAGER_PASSWORD', 'Adm!n@FileManager2024');
if (!isset($_SESSION['file_manager_authenticated']) || $_SESSION['file_manager_authenticated'] !== true) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fm_password'])) {
        if ($_POST['fm_password'] === FILE_MANAGER_PASSWORD) {
            $_SESSION['file_manager_authenticated'] = true;
            header('Location: ' . $_SERVER['REQUEST_URI']);
            exit;
        } else {
            $fmPasswordError = 'Incorrect password. Access denied.';
        }
    }
    if (!isset($_SESSION['file_manager_authenticated']) || $_SESSION['file_manager_authenticated'] !== true) {
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>File Manager - VLE Admin</title>
            <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
            <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
            <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
            <link href="../assets/css/global-theme.css" rel="stylesheet">
        </head>
        <body>
        <?php
        $currentPage = 'file_manager';
        $pageTitle = 'File Manager';
        include 'header_nav.php';
        ?>
        <div class="vle-content">
        <div class="container mt-5">
            <div class="row justify-content-center">
                <div class="col-md-5">
                    <div class="card shadow">
                        <div class="card-header bg-warning text-dark text-center">
                            <h4 class="mb-0"><i class="fas fa-lock me-2"></i>File Manager - Password Required</h4>
                        </div>
                        <div class="card-body p-4">
                            <?php if (isset($fmPasswordError)): ?>
                                <div class="alert alert-danger"><i class="fas fa-exclamation-circle me-1"></i><?= htmlspecialchars($fmPasswordError) ?></div>
                            <?php endif; ?>
                            <form method="POST">
                                <div class="mb-3">
                                    <label class="form-label">Enter Password</label>
                                    <input type="password" name="fm_password" class="form-control" placeholder="File Manager Password" required autofocus>
                                </div>
                                <button type="submit" class="btn btn-warning w-100"><i class="fas fa-unlock me-1"></i>Access File Manager</button>
                            </form>
                            <div class="text-center mt-3">
                                <a href="dashboard.php" class="text-muted"><i class="fas fa-arrow-left me-1"></i>Back to Dashboard</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        </div>
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
        </body>
        </html>
        <?php
        exit;
    }
}

$db = Database::getInstance();
$errors = [];
$success = '';

// Base directory - application root
$baseDir = realpath(__DIR__ . '/../');
$currentPath = isset($_GET['path']) ? $_GET['path'] : '';

// Sanitize and validate path
$currentPath = str_replace(['..', '\\'], ['', '/'], $currentPath);
$fullPath = realpath($baseDir . '/' . $currentPath);

// Security: Ensure path is within base directory
if ($fullPath === false || strpos($fullPath, $baseDir) !== 0) {
    $currentPath = '';
    $fullPath = $baseDir;
}

// Determine if current path is a file or directory
$isFile = is_file($fullPath);
$isDir = is_dir($fullPath);

// Editable file extensions
$editableExtensions = ['php', 'html', 'htm', 'css', 'js', 'json', 'txt', 'md', 'sql', 'xml', 'htaccess', 'env', 'ini', 'yml', 'yaml', 'log'];
$imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'ico', 'svg', 'webp', 'bmp'];
$downloadableExtensions = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'zip', 'rar', '7z', 'tar', 'gz'];

// Get file extension
function getFileExtension($filename) {
    return strtolower(pathinfo($filename, PATHINFO_EXTENSION));
}

// Get file icon based on extension
function getFileIcon($filename) {
    $ext = getFileExtension($filename);
    $icons = [
        'php' => 'fab fa-php text-purple',
        'html' => 'fab fa-html5 text-orange',
        'htm' => 'fab fa-html5 text-orange',
        'css' => 'fab fa-css3-alt text-primary',
        'js' => 'fab fa-js-square text-warning',
        'json' => 'fas fa-code text-info',
        'txt' => 'fas fa-file-alt text-secondary',
        'md' => 'fab fa-markdown text-dark',
        'sql' => 'fas fa-database text-success',
        'xml' => 'fas fa-code text-danger',
        'jpg' => 'fas fa-file-image text-success',
        'jpeg' => 'fas fa-file-image text-success',
        'png' => 'fas fa-file-image text-info',
        'gif' => 'fas fa-file-image text-warning',
        'svg' => 'fas fa-file-image text-purple',
        'ico' => 'fas fa-file-image text-muted',
        'pdf' => 'fas fa-file-pdf text-danger',
        'doc' => 'fas fa-file-word text-primary',
        'docx' => 'fas fa-file-word text-primary',
        'xls' => 'fas fa-file-excel text-success',
        'xlsx' => 'fas fa-file-excel text-success',
        'zip' => 'fas fa-file-archive text-warning',
        'rar' => 'fas fa-file-archive text-warning',
        'log' => 'fas fa-file-alt text-muted',
    ];
    return $icons[$ext] ?? 'fas fa-file text-muted';
}

// formatFileSize() is defined in config/config.php

// Get breadcrumb navigation
function getBreadcrumb($path) {
    $parts = explode('/', trim($path, '/'));
    $breadcrumb = [];
    $currentPath = '';
    
    foreach ($parts as $part) {
        if (empty($part)) continue;
        $currentPath .= '/' . $part;
        $breadcrumb[] = [
            'name' => $part,
            'path' => ltrim($currentPath, '/')
        ];
    }
    
    return $breadcrumb;
}

// Get file permissions string
function getFilePermissions($path) {
    $perms = fileperms($path);
    
    $info = '';
    // Owner
    $info .= (($perms & 0x0100) ? 'r' : '-');
    $info .= (($perms & 0x0080) ? 'w' : '-');
    $info .= (($perms & 0x0040) ? 'x' : '-');
    // Group
    $info .= (($perms & 0x0020) ? 'r' : '-');
    $info .= (($perms & 0x0010) ? 'w' : '-');
    $info .= (($perms & 0x0008) ? 'x' : '-');
    // Other
    $info .= (($perms & 0x0004) ? 'r' : '-');
    $info .= (($perms & 0x0002) ? 'w' : '-');
    $info .= (($perms & 0x0001) ? 'x' : '-');
    
    return $info;
}

// Handle AJAX upload request with progress
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload' && !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    @ini_set('max_execution_time', 600);
    header('Content-Type: application/json');
    if (!verifyCsrfToken()) {
        echo json_encode(['success' => false, 'message' => 'Invalid security token.']);
        exit;
    }
    if (!isset($_FILES['upload_files']) || empty($_FILES['upload_files']['name'][0])) {
        echo json_encode(['success' => false, 'message' => 'No files selected for upload.']);
        exit;
    }
    $uploadCount = 0;
    $failedFiles = [];
    $files = $_FILES['upload_files'];
    $fileCount = count($files['name']);
    for ($i = 0; $i < $fileCount; $i++) {
        if ($files['error'][$i] !== UPLOAD_ERR_OK) {
            $failedFiles[] = $files['name'][$i];
            continue;
        }
        $fileName = basename($files['name'][$i]);
        $fileName = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $fileName);
        $targetPath = $fullPath . '/' . $fileName;
        if (move_uploaded_file($files['tmp_name'][$i], $targetPath)) {
            $uploadCount++;
        } else {
            $failedFiles[] = $files['name'][$i];
        }
    }
    if ($uploadCount > 0) {
        $db->query(
            "INSERT INTO activity_log (user_id, action, description, ip_address, created_at) VALUES (?, ?, ?, ?, NOW())",
            [$_SESSION['user_id'], 'file_manager_upload', "Uploaded {$uploadCount} files to {$currentPath}", $_SERVER['REMOTE_ADDR'] ?? 'CLI']
        );
    }
    echo json_encode([
        'success' => $uploadCount > 0,
        'message' => $uploadCount > 0 ? "{$uploadCount} file(s) uploaded successfully." : 'No files were uploaded. Check permissions.',
        'uploaded' => $uploadCount,
        'failed' => $failedFiles
    ]);
    exit;
}

// Handle AJAX folder upload request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_folder' && !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    @ini_set('max_execution_time', 600);
    header('Content-Type: application/json');
    if (!verifyCsrfToken()) {
        echo json_encode(['success' => false, 'message' => 'Invalid security token.']);
        exit;
    }
    if (!isset($_FILES['folder_files']) || empty($_FILES['folder_files']['name'][0])) {
        echo json_encode(['success' => false, 'message' => 'No files selected.']);
        exit;
    }

    $overwrite = ($_POST['overwrite'] ?? '0') === '1';
    $relativePaths = $_POST['relative_paths'] ?? [];
    $files = $_FILES['folder_files'];
    $fileCount = count($files['name']);
    $uploadCount = 0;
    $failedFiles = [];
    $createdDirs = [];

    // Determine the root folder name from the first relative path
    $rootFolder = '';
    if (!empty($relativePaths[0])) {
        $parts = explode('/', $relativePaths[0]);
        $rootFolder = $parts[0] ?? '';
    }

    // If overwrite is enabled and root folder exists, delete it first
    if ($overwrite && !empty($rootFolder)) {
        $rootFolderClean = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $rootFolder);
        $existingPath = $fullPath . '/' . $rootFolderClean;
        if (is_dir($existingPath)) {
            // Recursive delete
            $delIt = new RecursiveDirectoryIterator($existingPath, RecursiveDirectoryIterator::SKIP_DOTS);
            $delFiles = new RecursiveIteratorIterator($delIt, RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($delFiles as $delFile) {
                if ($delFile->isDir()) {
                    @rmdir($delFile->getRealPath());
                } else {
                    @unlink($delFile->getRealPath());
                }
            }
            @rmdir($existingPath);
        }
    }

    for ($i = 0; $i < $fileCount; $i++) {
        if ($files['error'][$i] !== UPLOAD_ERR_OK) {
            $failedFiles[] = $files['name'][$i];
            continue;
        }

        // Use relative path to recreate folder structure
        $relPath = $relativePaths[$i] ?? $files['name'][$i];
        // Sanitize each path segment
        $segments = explode('/', $relPath);
        $sanitized = [];
        foreach ($segments as $seg) {
            $clean = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $seg);
            if (!empty($clean) && $clean !== '.' && $clean !== '..') {
                $sanitized[] = $clean;
            }
        }
        if (empty($sanitized)) {
            $failedFiles[] = $files['name'][$i];
            continue;
        }

        $targetRelPath = implode('/', $sanitized);
        $targetFull = $fullPath . '/' . $targetRelPath;
        $targetDir = dirname($targetFull);

        // Create directory structure if needed
        if (!is_dir($targetDir)) {
            if (!mkdir($targetDir, 0755, true)) {
                $failedFiles[] = $relPath;
                continue;
            }
        }

        // Security: ensure target is within base directory
        $realTarget = realpath($targetDir);
        if ($realTarget === false || strpos($realTarget, $baseDir) !== 0) {
            $failedFiles[] = $relPath;
            continue;
        }

        if (move_uploaded_file($files['tmp_name'][$i], $targetFull)) {
            $uploadCount++;
        } else {
            $failedFiles[] = $relPath;
        }
    }

    if ($uploadCount > 0) {
        $desc = "Uploaded folder" . ($rootFolder ? " '{$rootFolder}'" : '') . " ({$uploadCount} files) to {$currentPath}";
        if ($overwrite) $desc .= ' (overwrite)';
        $db->query(
            "INSERT INTO activity_log (user_id, action, description, ip_address, created_at) VALUES (?, ?, ?, ?, NOW())",
            [$_SESSION['user_id'], 'file_manager_upload_folder', $desc, $_SERVER['REMOTE_ADDR'] ?? 'CLI']
        );
    }

    echo json_encode([
        'success' => $uploadCount > 0,
        'message' => $uploadCount > 0
            ? "{$uploadCount} file(s) uploaded" . ($overwrite ? ' (folder overwritten)' : '') . '.'
            : 'No files were uploaded.',
        'uploaded' => $uploadCount,
        'failed' => $failedFiles,
        'folder' => $rootFolder
    ]);
    exit;
}

// Handle AJAX rename request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'rename' && !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    header('Content-Type: application/json');
    if (!verifyCsrfToken()) {
        echo json_encode(['success' => false, 'message' => 'Invalid security token.']);
        exit;
    }
    $oldPath = $_POST['old_path'] ?? '';
    $newName = sanitize($_POST['new_name'] ?? '');
    $newName = preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $newName);
    $realOldPath = realpath($baseDir . '/' . $oldPath);
    if ($realOldPath === false || strpos($realOldPath, $baseDir) !== 0 || $realOldPath === $baseDir) {
        echo json_encode(['success' => false, 'message' => 'Invalid source path.']);
        exit;
    }
    if (empty($newName)) {
        echo json_encode(['success' => false, 'message' => 'Invalid new name.']);
        exit;
    }
    $parentDir = dirname($realOldPath);
    $newPath = $parentDir . '/' . $newName;
    if (file_exists($newPath)) {
        echo json_encode(['success' => false, 'message' => 'A file/folder with that name already exists.']);
        exit;
    }
    if (rename($realOldPath, $newPath)) {
        $db->query(
            "INSERT INTO activity_log (user_id, action, description, ip_address, created_at) VALUES (?, ?, ?, ?, NOW())",
            [$_SESSION['user_id'], 'file_manager_rename', "Renamed: {$oldPath} to {$newName}", $_SERVER['REMOTE_ADDR'] ?? 'CLI']
        );
        echo json_encode(['success' => true, 'message' => 'Renamed successfully.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to rename.']);
    }
    exit;
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken()) {
        $errors[] = 'Invalid security token.';
    } else {
        $action = $_POST['action'] ?? '';
        
        switch ($action) {
            case 'create_folder':
                $folderName = sanitize($_POST['folder_name'] ?? '');
                $folderName = preg_replace('/[^a-zA-Z0-9_\-]/', '', $folderName);
                
                if (empty($folderName)) {
                    $errors[] = 'Invalid folder name.';
                    break;
                }
                
                $newPath = $fullPath . '/' . $folderName;
                
                if (file_exists($newPath)) {
                    $errors[] = 'Folder already exists.';
                    break;
                }
                
                if (mkdir($newPath, 0755)) {
                    $success = "Folder created successfully: {$folderName}";
                    // Log action
                    $db->query(
                        "INSERT INTO activity_log (user_id, action, description, ip_address, created_at) VALUES (?, ?, ?, ?, NOW())",
                        [$_SESSION['user_id'], 'file_manager_create_folder', "Created folder: {$folderName}", $_SERVER['REMOTE_ADDR'] ?? 'CLI']
                    );
                } else {
                    $errors[] = 'Failed to create folder.';
                }
                break;
                
            case 'create_file':
                $fileName = sanitize($_POST['file_name'] ?? '');
                $fileName = preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $fileName);
                
                if (empty($fileName)) {
                    $errors[] = 'Invalid file name.';
                    break;
                }
                
                $newPath = $fullPath . '/' . $fileName;
                
                if (file_exists($newPath)) {
                    $errors[] = 'File already exists.';
                    break;
                }
                
                if (file_put_contents($newPath, '') !== false) {
                    $success = "File created successfully: {$fileName}";
                    // Log action
                    $db->query(
                        "INSERT INTO activity_log (user_id, action, description, ip_address, created_at) VALUES (?, ?, ?, ?, NOW())",
                        [$_SESSION['user_id'], 'file_manager_create_file', "Created file: {$fileName}", $_SERVER['REMOTE_ADDR'] ?? 'CLI']
                    );
                } else {
                    $errors[] = 'Failed to create file.';
                }
                break;
                
            case 'upload':
                if (!isset($_FILES['upload_files']) || empty($_FILES['upload_files']['name'][0])) {
                    $errors[] = 'No files selected for upload.';
                    break;
                }
                
                $uploadCount = 0;
                $files = $_FILES['upload_files'];
                $fileCount = count($files['name']);
                
                for ($i = 0; $i < $fileCount; $i++) {
                    if ($files['error'][$i] !== UPLOAD_ERR_OK) {
                        continue;
                    }
                    
                    $fileName = basename($files['name'][$i]);
                    $fileName = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $fileName);
                    $targetPath = $fullPath . '/' . $fileName;
                    
                    if (move_uploaded_file($files['tmp_name'][$i], $targetPath)) {
                        $uploadCount++;
                    }
                }
                
                if ($uploadCount > 0) {
                    $success = "{$uploadCount} file(s) uploaded successfully.";
                    // Log action
                    $db->query(
                        "INSERT INTO activity_log (user_id, action, description, ip_address, created_at) VALUES (?, ?, ?, ?, NOW())",
                        [$_SESSION['user_id'], 'file_manager_upload', "Uploaded {$uploadCount} files to {$currentPath}", $_SERVER['REMOTE_ADDR'] ?? 'CLI']
                    );
                } else {
                    $errors[] = 'No files were uploaded. Check permissions.';
                }
                break;
                
            case 'save_file':
                $content = $_POST['file_content'] ?? '';
                $targetFile = $_POST['file_path'] ?? '';
                
                $realTarget = realpath($baseDir . '/' . $targetFile);
                
                if ($realTarget === false || strpos($realTarget, $baseDir) !== 0) {
                    $errors[] = 'Invalid file path.';
                    break;
                }
                
                if (!is_file($realTarget)) {
                    $errors[] = 'File not found.';
                    break;
                }
                
                if (file_put_contents($realTarget, $content) !== false) {
                    $success = 'File saved successfully.';
                    // Log action
                    $db->query(
                        "INSERT INTO activity_log (user_id, action, description, ip_address, created_at) VALUES (?, ?, ?, ?, NOW())",
                        [$_SESSION['user_id'], 'file_manager_edit', "Edited file: {$targetFile}", $_SERVER['REMOTE_ADDR'] ?? 'CLI']
                    );
                } else {
                    $errors[] = 'Failed to save file. Check permissions.';
                }
                break;
                
            case 'delete':
                $targetPath = $_POST['target_path'] ?? '';
                $realTarget = realpath($baseDir . '/' . $targetPath);
                
                if ($realTarget === false || strpos($realTarget, $baseDir) !== 0 || $realTarget === $baseDir) {
                    $errors[] = 'Invalid target path.';
                    break;
                }
                
                // Prevent deleting critical files/folders
                $protectedItems = ['config', 'includes', 'admin', 'auth', 'index.php', 'config.php', 'database.php'];
                $itemName = basename($realTarget);
                
                if (in_array($itemName, $protectedItems)) {
                    $errors[] = 'Cannot delete protected system files/folders.';
                    break;
                }
                
                if (is_dir($realTarget)) {
                    // Delete directory recursively
                    function deleteDirectory($dir) {
                        $files = array_diff(scandir($dir), ['.', '..']);
                        foreach ($files as $file) {
                            $path = $dir . '/' . $file;
                            is_dir($path) ? deleteDirectory($path) : unlink($path);
                        }
                        return rmdir($dir);
                    }
                    
                    if (deleteDirectory($realTarget)) {
                        $success = 'Folder deleted successfully.';
                        // Log action
                        $db->query(
                            "INSERT INTO activity_log (user_id, action, description, ip_address, created_at) VALUES (?, ?, ?, ?, NOW())",
                            [$_SESSION['user_id'], 'file_manager_delete', "Deleted folder: {$targetPath}", $_SERVER['REMOTE_ADDR'] ?? 'CLI']
                        );
                    } else {
                        $errors[] = 'Failed to delete folder.';
                    }
                } else {
                    if (unlink($realTarget)) {
                        $success = 'File deleted successfully.';
                        // Log action
                        $db->query(
                            "INSERT INTO activity_log (user_id, action, description, ip_address, created_at) VALUES (?, ?, ?, ?, NOW())",
                            [$_SESSION['user_id'], 'file_manager_delete', "Deleted file: {$targetPath}", $_SERVER['REMOTE_ADDR'] ?? 'CLI']
                        );
                    } else {
                        $errors[] = 'Failed to delete file.';
                    }
                }
                break;
                
            case 'rename':
                $oldPath = $_POST['old_path'] ?? '';
                $newName = sanitize($_POST['new_name'] ?? '');
                $newName = preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $newName);
                
                $realOldPath = realpath($baseDir . '/' . $oldPath);
                
                if ($realOldPath === false || strpos($realOldPath, $baseDir) !== 0 || $realOldPath === $baseDir) {
                    $errors[] = 'Invalid source path.';
                    break;
                }
                
                if (empty($newName)) {
                    $errors[] = 'Invalid new name.';
                    break;
                }
                
                $parentDir = dirname($realOldPath);
                $newPath = $parentDir . '/' . $newName;
                
                if (file_exists($newPath)) {
                    $errors[] = 'A file/folder with that name already exists.';
                    break;
                }
                
                if (rename($realOldPath, $newPath)) {
                    $success = 'Renamed successfully.';
                    // Log action
                    $db->query(
                        "INSERT INTO activity_log (user_id, action, description, ip_address, created_at) VALUES (?, ?, ?, ?, NOW())",
                        [$_SESSION['user_id'], 'file_manager_rename', "Renamed: {$oldPath} to {$newName}", $_SERVER['REMOTE_ADDR'] ?? 'CLI']
                    );
                } else {
                    $errors[] = 'Failed to rename.';
                }
                break;
                
            case 'download':
                $downloadPath = $_POST['download_path'] ?? '';
                $realDownload = realpath($baseDir . '/' . $downloadPath);
                
                if ($realDownload === false || strpos($realDownload, $baseDir) !== 0 || !is_file($realDownload)) {
                    $errors[] = 'Invalid download path.';
                    break;
                }
                
                header('Content-Type: application/octet-stream');
                header('Content-Disposition: attachment; filename="' . basename($realDownload) . '"');
                header('Content-Length: ' . filesize($realDownload));
                readfile($realDownload);
                exit;
                break;
        }
    }
}

// Get directory contents
$contents = [];
if ($isDir) {
    $items = scandir($fullPath);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        
        $itemPath = $fullPath . '/' . $item;
        $relativePath = $currentPath ? $currentPath . '/' . $item : $item;
        
        $contents[] = [
            'name' => $item,
            'path' => $relativePath,
            'is_dir' => is_dir($itemPath),
            'size' => is_file($itemPath) ? filesize($itemPath) : 0,
            'modified' => filemtime($itemPath),
            'permissions' => getFilePermissions($itemPath),
            'extension' => is_file($itemPath) ? getFileExtension($item) : '',
            'is_editable' => is_file($itemPath) && in_array(getFileExtension($item), $editableExtensions),
            'is_image' => is_file($itemPath) && in_array(getFileExtension($item), $imageExtensions),
        ];
    }
    
    // Sort: directories first, then files
    usort($contents, function($a, $b) {
        if ($a['is_dir'] && !$b['is_dir']) return -1;
        if (!$a['is_dir'] && $b['is_dir']) return 1;
        return strcasecmp($a['name'], $b['name']);
    });
}

// Get file content if viewing a file
$fileContent = '';
$ext = '';
if ($isFile && in_array(getFileExtension(basename($fullPath)), $editableExtensions)) {
    $fileContent = file_get_contents($fullPath);
    $ext = getFileExtension(basename($fullPath));
}

// Get breadcrumb
$breadcrumb = getBreadcrumb($currentPath);

$pageTitle = 'System File Manager';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>File Manager - VLE Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
</head>
<body>
<?php
$currentPage = 'file_manager';
include 'header_nav.php';
?>

<style>
.file-item {
    transition: all 0.2s ease;
    cursor: pointer;
}
.file-item:hover {
    background-color: rgba(0,123,255,0.1);
}
.file-icon {
    font-size: 1.5rem;
    width: 40px;
    text-align: center;
}
.folder-icon {
    color: #ffc107;
}
.text-purple {
    color: #6f42c1;
}
.text-orange {
    color: #fd7e14;
}
.file-editor {
    font-family: 'Consolas', 'Monaco', 'Courier New', monospace;
    font-size: 13px;
    line-height: 1.4;
    tab-size: 4;
}
.breadcrumb-item + .breadcrumb-item::before {
    content: "›";
}
.file-actions {
    opacity: 0;
    transition: opacity 0.2s;
}
.file-item:hover .file-actions {
    opacity: 1;
}
.image-preview {
    max-width: 100%;
    max-height: 400px;
    object-fit: contain;
}
</style>

<div class="vle-content">
<div class="container-fluid py-4">
            <!-- Quick Navigation -->
            <nav class="navbar navbar-expand navbar-light bg-light rounded mb-3 px-3">
                <a href="dashboard.php" class="btn btn-sm btn-outline-primary me-2">
                    <i class="fas fa-arrow-left me-1"></i>Dashboard
                </a>
                <div class="navbar-nav ms-auto small">
                    <a class="nav-link" href="student_reports.php"><i class="fas fa-chart-bar me-1"></i>Reports</a>
                    <a class="nav-link" href="database_manager.php"><i class="fas fa-database me-1"></i>Database</a>
                </div>
            </nav>

            <div class="d-flex justify-content-between align-items-center mb-4">
                <h4><i class="fas fa-folder-open me-2"></i>System File Manager</h4>
                <div>
                    <span class="badge bg-primary"><i class="fas fa-folder me-1"></i><?= count(array_filter($contents, fn($c) => $c['is_dir'])) ?> folders</span>
                    <span class="badge bg-info"><i class="fas fa-file me-1"></i><?= count(array_filter($contents, fn($c) => !$c['is_dir'])) ?> files</span>
                </div>
            </div>

            <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <i class="fas fa-check-circle me-2"></i><?= $success ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <?php foreach ($errors as $error): ?>
                        <p class="mb-0"><i class="fas fa-exclamation-circle me-2"></i><?= $error ?></p>
                    <?php endforeach; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Breadcrumb Navigation -->
            <nav aria-label="breadcrumb" class="mb-3">
                <ol class="breadcrumb bg-light p-2 rounded">
                    <li class="breadcrumb-item">
                        <a href="?path=" class="text-decoration-none">
                            <i class="fas fa-home me-1"></i>Root
                        </a>
                    </li>
                    <?php foreach ($breadcrumb as $crumb): ?>
                        <li class="breadcrumb-item">
                            <a href="?path=<?= urlencode($crumb['path']) ?>" class="text-decoration-none">
                                <?= htmlspecialchars($crumb['name']) ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ol>
            </nav>

            <?php if ($isDir): ?>
                <!-- Toolbar -->
                <div class="card mb-3">
                    <div class="card-body py-2">
                        <div class="row align-items-center">
                            <div class="col-md-8">
                                <button type="button" class="btn btn-sm btn-primary me-2" data-bs-toggle="modal" data-bs-target="#createFolderModal">
                                    <i class="fas fa-folder-plus me-1"></i>New Folder
                                </button>
                                <button type="button" class="btn btn-sm btn-success me-2" data-bs-toggle="modal" data-bs-target="#createFileModal">
                                    <i class="fas fa-file-plus me-1"></i>New File
                                </button>
                                <button type="button" class="btn btn-sm btn-info me-2" data-bs-toggle="modal" data-bs-target="#uploadModal">
                                    <i class="fas fa-cloud-upload-alt me-1"></i>Upload Files
                                </button>
                                <button type="button" class="btn btn-sm btn-warning me-2" data-bs-toggle="modal" data-bs-target="#uploadFolderModal">
                                    <i class="fas fa-folder-plus me-1"></i>Upload Folder
                                </button>
                            </div>
                            <div class="col-md-4 text-end">
                                <small class="text-muted">
                                    <i class="fas fa-map-marker-alt me-1"></i>
                                    <?= htmlspecialchars($currentPath ?: '/') ?>
                                </small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- File/Folder List -->
                <div class="card">
                    <div class="card-header bg-dark text-white py-2">
                        <div class="row">
                            <div class="col-md-5"><i class="fas fa-file-alt me-2"></i>Name</div>
                            <div class="col-md-2 text-center">Size</div>
                            <div class="col-md-2 text-center">Modified</div>
                            <div class="col-md-1 text-center">Perms</div>
                            <div class="col-md-2 text-end">Actions</div>
                        </div>
                    </div>
                    <div class="list-group list-group-flush">
                        <?php if (!empty($currentPath)): ?>
                            <a href="?path=<?= urlencode(dirname($currentPath)) ?>" class="list-group-item list-group-item-action file-item">
                                <div class="row align-items-center">
                                    <div class="col-md-5">
                                        <span class="file-icon"><i class="fas fa-level-up-alt text-muted"></i></span>
                                        <strong>..</strong> <small class="text-muted">(Parent Directory)</small>
                                    </div>
                                </div>
                            </a>
                        <?php endif; ?>
                        
                        <?php if (empty($contents)): ?>
                            <div class="list-group-item text-center py-5 text-muted">
                                <i class="fas fa-folder-open fa-3x mb-3"></i>
                                <p>This folder is empty</p>
                            </div>
                        <?php endif; ?>
                        
                        <?php foreach ($contents as $item): ?>
                            <div class="list-group-item file-item">
                                <div class="row align-items-center">
                                    <div class="col-md-5">
                                        <?php if ($item['is_dir']): ?>
                                            <a href="?path=<?= urlencode($item['path']) ?>" class="text-decoration-none text-dark">
                                                <span class="file-icon"><i class="fas fa-folder folder-icon"></i></span>
                                                <strong><?= htmlspecialchars($item['name']) ?></strong>
                                            </a>
                                        <?php elseif ($item['is_editable']): ?>
                                            <a href="?path=<?= urlencode($item['path']) ?>" class="text-decoration-none text-dark">
                                                <span class="file-icon"><i class="<?= getFileIcon($item['name']) ?>"></i></span>
                                                <?= htmlspecialchars($item['name']) ?>
                                            </a>
                                        <?php elseif ($item['is_image']): ?>
                                            <a href="#" class="text-decoration-none text-dark" data-bs-toggle="modal" 
                                               data-bs-target="#imagePreviewModal" 
                                               data-image="<?= htmlspecialchars(APP_URL . '/' . $item['path']) ?>"
                                               data-name="<?= htmlspecialchars($item['name']) ?>">
                                                <span class="file-icon"><i class="<?= getFileIcon($item['name']) ?>"></i></span>
                                                <?= htmlspecialchars($item['name']) ?>
                                            </a>
                                        <?php else: ?>
                                            <span class="file-icon"><i class="<?= getFileIcon($item['name']) ?>"></i></span>
                                            <?= htmlspecialchars($item['name']) ?>
                                        <?php endif; ?>
                                    </div>
                                    <div class="col-md-2 text-center">
                                        <?php if (!$item['is_dir']): ?>
                                            <small class="text-muted"><?= formatFileSize($item['size']) ?></small>
                                        <?php else: ?>
                                            <small class="text-muted">--</small>
                                        <?php endif; ?>
                                    </div>
                                    <div class="col-md-2 text-center">
                                        <small class="text-muted"><?= date('M d, Y H:i', $item['modified']) ?></small>
                                    </div>
                                    <div class="col-md-1 text-center">
                                        <small class="text-muted font-monospace"><?= $item['permissions'] ?></small>
                                    </div>
                                    <div class="col-md-2 text-end file-actions">
                                        <button type="button" class="btn btn-sm btn-outline-secondary rename-btn" 
                                                data-path="<?= htmlspecialchars($item['path']) ?>"
                                                data-name="<?= htmlspecialchars($item['name']) ?>"
                                                title="Rename">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <?php if (!$item['is_dir']): ?>
                                            <form method="POST" class="d-inline">
                                                <?= csrfTokenField() ?>
                                                <input type="hidden" name="action" value="download">
                                                <input type="hidden" name="download_path" value="<?= htmlspecialchars($item['path']) ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-primary" title="Download">
                                                    <i class="fas fa-download"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                        <form method="POST" class="d-inline delete-form">
                                            <?= csrfTokenField() ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="target_path" value="<?= htmlspecialchars($item['path']) ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete"
                                                    onclick="return confirm('Are you sure you want to delete <?= $item['is_dir'] ? 'this folder and all its contents' : 'this file' ?>?');">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

            <?php elseif ($isFile): ?>
                <!-- File Editor / Viewer -->
                <div class="card">
                    <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                        <span>
                            <i class="<?= getFileIcon(basename($fullPath)) ?> me-2"></i>
                            <?= htmlspecialchars(basename($fullPath)) ?>
                            <small class="text-muted ms-2">(<?= formatFileSize(filesize($fullPath)) ?>)</small>
                        </span>
                        <div>
                            <a href="?path=<?= urlencode(dirname($currentPath)) ?>" class="btn btn-sm btn-secondary me-2">
                                <i class="fas fa-arrow-left me-1"></i>Back
                            </a>
                            <form method="POST" class="d-inline">
                                <?= csrfTokenField() ?>
                                <input type="hidden" name="action" value="download">
                                <input type="hidden" name="download_path" value="<?= htmlspecialchars($currentPath) ?>">
                                <button type="submit" class="btn btn-sm btn-primary">
                                    <i class="fas fa-download me-1"></i>Download
                                </button>
                            </form>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <?php if (in_array($ext, $editableExtensions)): ?>
                            <form method="POST">
                                <?= csrfTokenField() ?>
                                <input type="hidden" name="action" value="save_file">
                                <input type="hidden" name="file_path" value="<?= htmlspecialchars($currentPath) ?>">
                                <textarea name="file_content" class="form-control file-editor border-0 rounded-0" 
                                          rows="30" spellcheck="false"><?= htmlspecialchars($fileContent) ?></textarea>
                                <div class="card-footer">
                                    <button type="submit" class="btn btn-success">
                                        <i class="fas fa-save me-1"></i>Save Changes
                                    </button>
                                    <a href="?path=<?= urlencode(dirname($currentPath)) ?>" class="btn btn-secondary">
                                        Cancel
                                    </a>
                                </div>
                            </form>
                        <?php elseif (in_array($ext, $imageExtensions)): ?>
                            <div class="text-center p-4">
                                <img src="<?= htmlspecialchars(APP_URL . '/' . $currentPath) ?>" alt="Image Preview" class="image-preview">
                            </div>
                        <?php else: ?>
                            <div class="p-4 text-center text-muted">
                                <i class="fas fa-file fa-5x mb-3"></i>
                                <p>This file type cannot be edited or previewed.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Create Folder Modal -->
<div class="modal fade" id="createFolderModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <?= csrfTokenField() ?>
                <input type="hidden" name="action" value="create_folder">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-folder-plus me-2"></i>Create New Folder</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Folder Name</label>
                        <input type="text" class="form-control" name="folder_name" required 
                               pattern="[a-zA-Z0-9_\-]+" placeholder="my_folder">
                        <small class="text-muted">Allowed characters: letters, numbers, underscore, dash</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create Folder</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Create File Modal -->
<div class="modal fade" id="createFileModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <?= csrfTokenField() ?>
                <input type="hidden" name="action" value="create_file">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-file-plus me-2"></i>Create New File</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">File Name</label>
                        <input type="text" class="form-control" name="file_name" required 
                               pattern="[a-zA-Z0-9_\-\.]+" placeholder="example.php">
                        <small class="text-muted">Include file extension (e.g., .php, .css, .js)</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Create File</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Upload Files Modal -->
<div class="modal fade" id="uploadModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="uploadForm" method="POST" enctype="multipart/form-data">
                <?= csrfTokenField() ?>
                <input type="hidden" name="action" value="upload">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-cloud-upload-alt me-2"></i>Upload Files</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3" id="uploadFileInput">
                        <label class="form-label">Select Files</label>
                        <input type="file" class="form-control" name="upload_files[]" id="uploadFiles" multiple required>
                        <small class="text-muted">Select one or multiple files to upload</small>
                    </div>
                    <div class="alert alert-info small">
                        <i class="fas fa-info-circle me-2"></i>
                        Files will be uploaded to: <strong><?= htmlspecialchars($currentPath ?: '/') ?></strong>
                    </div>
                    <!-- Upload Progress Section (hidden by default) -->
                    <div id="uploadProgressWrap" style="display:none;">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <small class="fw-bold text-muted" id="uploadStatusText">Preparing upload...</small>
                            <small class="fw-bold" id="uploadPercentText">0%</small>
                        </div>
                        <div class="progress" style="height:22px; border-radius:12px; background:#e9ecef;">
                            <div id="uploadProgressBar" class="progress-bar progress-bar-striped progress-bar-animated bg-info" role="progressbar" style="width:0%; border-radius:12px; transition: width 0.2s ease;" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100">0%</div>
                        </div>
                        <div class="d-flex justify-content-between mt-1">
                            <small class="text-muted" id="uploadSizeText"></small>
                            <small class="text-muted" id="uploadSpeedText"></small>
                        </div>
                        <div id="uploadResultMsg" class="mt-2" style="display:none;"></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" id="uploadCancelBtn">Cancel</button>
                    <button type="submit" class="btn btn-info" id="uploadSubmitBtn"><i class="fas fa-cloud-upload-alt me-1"></i>Upload Files</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Upload Folder Modal -->
<div class="modal fade" id="uploadFolderModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="uploadFolderForm">
                <?= csrfTokenField() ?>
                <input type="hidden" name="action" value="upload_folder">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title"><i class="fas fa-folder-plus me-2"></i>Upload Folder</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3" id="folderFileInput">
                        <label class="form-label">Select Folder</label>
                        <input type="file" class="form-control" name="folder_files[]" id="folderFiles" webkitdirectory directory multiple required>
                        <small class="text-muted">Select a folder — all its files and subfolders will be uploaded</small>
                    </div>
                    <div id="folderPreview" style="display:none;" class="mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <strong id="folderPreviewName" class="text-primary"></strong>
                            <small id="folderPreviewCount" class="text-muted"></small>
                        </div>
                        <div id="folderPreviewTree" class="border rounded p-2 small" style="max-height:180px; overflow-y:auto; background:#f8f9fa; font-family:monospace;"></div>
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="overwriteFolder" name="overwrite" value="1" checked>
                        <label class="form-check-label" for="overwriteFolder">
                            <strong>Overwrite</strong> if folder already exists
                        </label>
                        <div class="form-text text-danger small">If checked, the existing folder and all its contents will be replaced.</div>
                    </div>
                    <div class="alert alert-info small mb-0">
                        <i class="fas fa-info-circle me-1"></i>
                        Folder will be uploaded to: <strong><?= htmlspecialchars($currentPath ?: '/') ?></strong>
                    </div>
                    <!-- Upload Folder Progress -->
                    <div id="folderProgressWrap" style="display:none;" class="mt-3">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <small class="fw-bold text-muted" id="folderStatusText">Preparing upload...</small>
                            <small class="fw-bold" id="folderPercentText">0%</small>
                        </div>
                        <div class="progress" style="height:22px; border-radius:12px; background:#e9ecef;">
                            <div id="folderProgressBar" class="progress-bar progress-bar-striped progress-bar-animated bg-warning" role="progressbar" style="width:0%; border-radius:12px; transition: width 0.2s ease;">0%</div>
                        </div>
                        <div class="d-flex justify-content-between mt-1">
                            <small class="text-muted" id="folderSizeText"></small>
                            <small class="text-muted" id="folderSpeedText"></small>
                        </div>
                        <div id="folderResultMsg" class="mt-2" style="display:none;"></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" id="folderCancelBtn">Cancel</button>
                    <button type="submit" class="btn btn-warning" id="folderSubmitBtn"><i class="fas fa-folder-plus me-1"></i>Upload Folder</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Rename Modal -->
<div class="modal fade" id="renameModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <?= csrfTokenField() ?>
                <input type="hidden" name="action" value="rename">
                <input type="hidden" name="old_path" id="renameOldPath">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Rename</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">New Name</label>
                        <input type="text" class="form-control" name="new_name" id="renameNewName" required 
                               pattern="[a-zA-Z0-9_\-\.]+">
                        <small class="text-muted">Allowed characters: letters, numbers, underscore, dash, period</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Rename</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Image Preview Modal -->
<div class="modal fade" id="imagePreviewModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="imagePreviewTitle"><i class="fas fa-image me-2"></i>Image Preview</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center">
                <img src="" alt="Preview" id="imagePreviewImg" class="image-preview">
            </div>
        </div>
    </div>
</div>

<script>
// Rename button handler
document.querySelectorAll('.rename-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        const path = this.dataset.path;
        const name = this.dataset.name;
        document.getElementById('renameOldPath').value = path;
        document.getElementById('renameNewName').value = name;
        new bootstrap.Modal(document.getElementById('renameModal')).show();
    });
});

// AJAX rename form submission - save before refreshing
document.querySelector('#renameModal form').addEventListener('submit', function(e) {
    e.preventDefault();
    const form = this;
    const formData = new FormData(form);
    const submitBtn = form.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Renaming...';
    submitBtn.disabled = true;

    fetch(window.location.href, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Close modal and refresh page after successful rename
            bootstrap.Modal.getInstance(document.getElementById('renameModal')).hide();
            const alert = document.createElement('div');
            alert.className = 'alert alert-success alert-dismissible fade show position-fixed top-0 start-50 translate-middle-x mt-3';
            alert.style.zIndex = '9999';
            alert.innerHTML = '<i class="fas fa-check-circle me-1"></i>' + data.message + '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
            document.body.appendChild(alert);
            setTimeout(() => { window.location.reload(); }, 800);
        } else {
            const alertDiv = form.querySelector('.rename-alert') || document.createElement('div');
            alertDiv.className = 'alert alert-danger rename-alert mt-2';
            alertDiv.innerHTML = '<i class="fas fa-exclamation-circle me-1"></i>' + data.message;
            if (!form.querySelector('.rename-alert')) {
                form.querySelector('.modal-body').appendChild(alertDiv);
            }
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
        }
    })
    .catch(() => {
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
        alert('An error occurred. Please try again.');
    });
});

// Upload with progress bar
(function() {
    const form = document.getElementById('uploadForm');
    if (!form) return;
    const fileInput = document.getElementById('uploadFiles');
    const progressWrap = document.getElementById('uploadProgressWrap');
    const progressBar = document.getElementById('uploadProgressBar');
    const percentText = document.getElementById('uploadPercentText');
    const statusText = document.getElementById('uploadStatusText');
    const sizeText = document.getElementById('uploadSizeText');
    const speedText = document.getElementById('uploadSpeedText');
    const resultMsg = document.getElementById('uploadResultMsg');
    const submitBtn = document.getElementById('uploadSubmitBtn');
    const cancelBtn = document.getElementById('uploadCancelBtn');
    const fileInputWrap = document.getElementById('uploadFileInput');

    function formatBytes(bytes) {
        if (bytes === 0) return '0 B';
        const k = 1024, sizes = ['B','KB','MB','GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
    }

    form.addEventListener('submit', function(e) {
        e.preventDefault();
        if (!fileInput.files.length) return;

        const formData = new FormData(form);
        const xhr = new XMLHttpRequest();
        const startTime = Date.now();
        let totalSize = 0;
        for (let f of fileInput.files) totalSize += f.size;

        // Show progress UI
        progressWrap.style.display = 'block';
        resultMsg.style.display = 'none';
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Uploading...';
        cancelBtn.disabled = true;
        statusText.textContent = 'Uploading ' + fileInput.files.length + ' file(s)...';
        sizeText.textContent = '0 B / ' + formatBytes(totalSize);
        speedText.textContent = '';

        xhr.upload.addEventListener('progress', function(ev) {
            if (ev.lengthComputable) {
                const pct = Math.round((ev.loaded / ev.total) * 100);
                progressBar.style.width = pct + '%';
                progressBar.textContent = pct + '%';
                progressBar.setAttribute('aria-valuenow', pct);
                percentText.textContent = pct + '%';
                sizeText.textContent = formatBytes(ev.loaded) + ' / ' + formatBytes(ev.total);
                const elapsed = (Date.now() - startTime) / 1000;
                if (elapsed > 0.5) {
                    const speed = ev.loaded / elapsed;
                    speedText.textContent = formatBytes(speed) + '/s';
                }
                if (pct >= 100) {
                    statusText.textContent = 'Processing on server...';
                    progressBar.classList.remove('bg-info');
                    progressBar.classList.add('bg-warning');
                }
            }
        });

        xhr.addEventListener('load', function() {
            cancelBtn.disabled = false;
            try {
                const data = JSON.parse(xhr.responseText);
                progressBar.classList.remove('bg-info','bg-warning','progress-bar-animated');
                if (data.success) {
                    progressBar.style.width = '100%';
                    progressBar.textContent = '100%';
                    progressBar.classList.add('bg-success');
                    statusText.textContent = 'Upload complete!';
                    percentText.textContent = '100%';
                    let msg = '<div class="alert alert-success small py-2 mb-0"><i class="fas fa-check-circle me-1"></i>' + data.message + '</div>';
                    if (data.failed && data.failed.length > 0) {
                        msg += '<div class="alert alert-warning small py-2 mb-0 mt-1"><i class="fas fa-exclamation-triangle me-1"></i>Failed: ' + data.failed.join(', ') + '</div>';
                    }
                    resultMsg.innerHTML = msg;
                    resultMsg.style.display = 'block';
                    cancelBtn.textContent = 'Close';
                    submitBtn.style.display = 'none';
                    setTimeout(function() { window.location.reload(); }, 1500);
                } else {
                    progressBar.classList.add('bg-danger');
                    statusText.textContent = 'Upload failed';
                    resultMsg.innerHTML = '<div class="alert alert-danger small py-2 mb-0"><i class="fas fa-times-circle me-1"></i>' + data.message + '</div>';
                    resultMsg.style.display = 'block';
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = '<i class="fas fa-cloud-upload-alt me-1"></i>Retry Upload';
                }
            } catch(err) {
                progressBar.classList.remove('bg-info','bg-warning','progress-bar-animated');
                progressBar.classList.add('bg-danger');
                statusText.textContent = 'Upload failed';
                resultMsg.innerHTML = '<div class="alert alert-danger small py-2 mb-0"><i class="fas fa-times-circle me-1"></i>Server error. Please try again.</div>';
                resultMsg.style.display = 'block';
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fas fa-cloud-upload-alt me-1"></i>Retry Upload';
            }
        });

        xhr.addEventListener('error', function() {
            cancelBtn.disabled = false;
            progressBar.classList.remove('bg-info','bg-warning','progress-bar-animated');
            progressBar.classList.add('bg-danger');
            statusText.textContent = 'Network error';
            resultMsg.innerHTML = '<div class="alert alert-danger small py-2 mb-0"><i class="fas fa-times-circle me-1"></i>Network error. Check your connection.</div>';
            resultMsg.style.display = 'block';
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="fas fa-cloud-upload-alt me-1"></i>Retry Upload';
        });

        xhr.open('POST', window.location.href, true);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.send(formData);
    });

    // Reset modal state when closed
    document.getElementById('uploadModal').addEventListener('hidden.bs.modal', function() {
        progressWrap.style.display = 'none';
        progressBar.style.width = '0%';
        progressBar.textContent = '0%';
        progressBar.className = 'progress-bar progress-bar-striped progress-bar-animated bg-info';
        percentText.textContent = '0%';
        statusText.textContent = 'Preparing upload...';
        sizeText.textContent = '';
        speedText.textContent = '';
        resultMsg.style.display = 'none';
        resultMsg.innerHTML = '';
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i class="fas fa-cloud-upload-alt me-1"></i>Upload Files';
        submitBtn.style.display = '';
        cancelBtn.disabled = false;
        cancelBtn.textContent = 'Cancel';
        fileInput.value = '';
    });
})();

// Upload Folder handler
(function() {
    const form = document.getElementById('uploadFolderForm');
    if (!form) return;
    const fileInput = document.getElementById('folderFiles');
    const preview = document.getElementById('folderPreview');
    const previewName = document.getElementById('folderPreviewName');
    const previewCount = document.getElementById('folderPreviewCount');
    const previewTree = document.getElementById('folderPreviewTree');
    const progressWrap = document.getElementById('folderProgressWrap');
    const progressBar = document.getElementById('folderProgressBar');
    const percentText = document.getElementById('folderPercentText');
    const statusText = document.getElementById('folderStatusText');
    const sizeText = document.getElementById('folderSizeText');
    const speedText = document.getElementById('folderSpeedText');
    const resultMsg = document.getElementById('folderResultMsg');
    const submitBtn = document.getElementById('folderSubmitBtn');
    const cancelBtn = document.getElementById('folderCancelBtn');

    function formatBytes(bytes) {
        if (bytes === 0) return '0 B';
        const k = 1024, sizes = ['B','KB','MB','GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
    }

    // Show folder preview when selected
    fileInput.addEventListener('change', function() {
        const files = this.files;
        if (!files.length) { preview.style.display = 'none'; return; }

        // Get root folder name from first file
        const rootName = files[0].webkitRelativePath.split('/')[0];
        let totalSize = 0;
        const paths = [];
        for (let f of files) {
            totalSize += f.size;
            paths.push(f.webkitRelativePath);
        }

        previewName.innerHTML = '<i class="fas fa-folder text-warning me-1"></i>' + rootName;
        previewCount.textContent = files.length + ' files (' + formatBytes(totalSize) + ')';

        // Build a simple tree view (limit to 50 items)
        let html = '';
        const show = paths.slice(0, 50);
        show.forEach(p => {
            const depth = (p.split('/').length - 1);
            html += '<div style="padding-left:' + (depth * 12) + 'px">';
            html += '<i class="fas fa-file text-muted me-1" style="font-size:0.7rem"></i>';
            html += p.split('/').pop();
            html += '</div>';
        });
        if (paths.length > 50) {
            html += '<div class="text-muted mt-1">... and ' + (paths.length - 50) + ' more files</div>';
        }
        previewTree.innerHTML = html;
        preview.style.display = 'block';
    });

    form.addEventListener('submit', function(e) {
        e.preventDefault();
        const files = fileInput.files;
        if (!files.length) return;

        const formData = new FormData();
        // Add CSRF token
        const csrfInput = form.querySelector('input[name="csrf_token"]');
        if (csrfInput) formData.append('csrf_token', csrfInput.value);
        formData.append('action', 'upload_folder');
        formData.append('overwrite', document.getElementById('overwriteFolder').checked ? '1' : '0');

        let totalSize = 0;
        for (let i = 0; i < files.length; i++) {
            formData.append('folder_files[]', files[i]);
            formData.append('relative_paths[]', files[i].webkitRelativePath);
            totalSize += files[i].size;
        }

        const xhr = new XMLHttpRequest();
        const startTime = Date.now();

        // Show progress UI
        progressWrap.style.display = 'block';
        resultMsg.style.display = 'none';
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Uploading...';
        cancelBtn.disabled = true;
        statusText.textContent = 'Uploading ' + files.length + ' file(s)...';
        sizeText.textContent = '0 B / ' + formatBytes(totalSize);
        speedText.textContent = '';

        xhr.upload.addEventListener('progress', function(ev) {
            if (ev.lengthComputable) {
                const pct = Math.round((ev.loaded / ev.total) * 100);
                progressBar.style.width = pct + '%';
                progressBar.textContent = pct + '%';
                percentText.textContent = pct + '%';
                sizeText.textContent = formatBytes(ev.loaded) + ' / ' + formatBytes(ev.total);
                const elapsed = (Date.now() - startTime) / 1000;
                if (elapsed > 0.5) speedText.textContent = formatBytes(ev.loaded / elapsed) + '/s';
                if (pct >= 100) {
                    statusText.textContent = 'Processing on server...';
                    progressBar.classList.remove('bg-warning');
                    progressBar.classList.add('bg-info');
                }
            }
        });

        xhr.addEventListener('load', function() {
            cancelBtn.disabled = false;
            try {
                const data = JSON.parse(xhr.responseText);
                progressBar.classList.remove('bg-warning','bg-info','progress-bar-animated');
                if (data.success) {
                    progressBar.style.width = '100%';
                    progressBar.textContent = '100%';
                    progressBar.classList.add('bg-success');
                    statusText.textContent = 'Folder uploaded!';
                    percentText.textContent = '100%';
                    let msg = '<div class="alert alert-success small py-2 mb-0"><i class="fas fa-check-circle me-1"></i>' + data.message + '</div>';
                    if (data.failed && data.failed.length > 0) {
                        msg += '<div class="alert alert-warning small py-2 mb-0 mt-1"><i class="fas fa-exclamation-triangle me-1"></i>Failed: ' + data.failed.join(', ') + '</div>';
                    }
                    resultMsg.innerHTML = msg;
                    resultMsg.style.display = 'block';
                    cancelBtn.textContent = 'Close';
                    submitBtn.style.display = 'none';
                    setTimeout(function() { window.location.reload(); }, 1500);
                } else {
                    progressBar.classList.add('bg-danger');
                    statusText.textContent = 'Upload failed';
                    resultMsg.innerHTML = '<div class="alert alert-danger small py-2 mb-0"><i class="fas fa-times-circle me-1"></i>' + data.message + '</div>';
                    resultMsg.style.display = 'block';
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = '<i class="fas fa-folder-plus me-1"></i>Retry';
                }
            } catch(err) {
                progressBar.classList.remove('bg-warning','bg-info','progress-bar-animated');
                progressBar.classList.add('bg-danger');
                statusText.textContent = 'Upload failed';
                resultMsg.innerHTML = '<div class="alert alert-danger small py-2 mb-0"><i class="fas fa-times-circle me-1"></i>Server error. Please try again.</div>';
                resultMsg.style.display = 'block';
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fas fa-folder-plus me-1"></i>Retry';
            }
        });

        xhr.addEventListener('error', function() {
            cancelBtn.disabled = false;
            progressBar.classList.remove('bg-warning','bg-info','progress-bar-animated');
            progressBar.classList.add('bg-danger');
            statusText.textContent = 'Network error';
            resultMsg.innerHTML = '<div class="alert alert-danger small py-2 mb-0"><i class="fas fa-times-circle me-1"></i>Network error. Check your connection.</div>';
            resultMsg.style.display = 'block';
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="fas fa-folder-plus me-1"></i>Retry';
        });

        xhr.open('POST', window.location.href, true);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.send(formData);
    });

    // Reset modal state when closed
    document.getElementById('uploadFolderModal').addEventListener('hidden.bs.modal', function() {
        progressWrap.style.display = 'none';
        progressBar.style.width = '0%';
        progressBar.textContent = '0%';
        progressBar.className = 'progress-bar progress-bar-striped progress-bar-animated bg-warning';
        percentText.textContent = '0%';
        statusText.textContent = 'Preparing upload...';
        sizeText.textContent = '';
        speedText.textContent = '';
        resultMsg.style.display = 'none';
        resultMsg.innerHTML = '';
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i class="fas fa-folder-plus me-1"></i>Upload Folder';
        submitBtn.style.display = '';
        cancelBtn.disabled = false;
        cancelBtn.textContent = 'Cancel';
        preview.style.display = 'none';
        fileInput.value = '';
    });
})();

// Image preview handler
document.getElementById('imagePreviewModal').addEventListener('show.bs.modal', function(event) {
    const button = event.relatedTarget;
    if (button && button.dataset.image) {
        document.getElementById('imagePreviewImg').src = button.dataset.image;
        document.getElementById('imagePreviewTitle').innerHTML = '<i class="fas fa-image me-2"></i>' + button.dataset.name;
    }
});

// Keyboard shortcut for saving file (Ctrl+S)
document.addEventListener('keydown', function(e) {
    if (e.ctrlKey && e.key === 's') {
        const form = document.querySelector('form[action] input[name="action"][value="save_file"]');
        if (form) {
            e.preventDefault();
            form.closest('form').submit();
        }
    }
});
</script>

</div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
