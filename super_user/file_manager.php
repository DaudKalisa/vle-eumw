<?php
/**
 * Super User - System File Manager
 * Browse, upload, edit, and manage system files
 * Only accessible by authenticated super users
 */
session_start();

// Check if logged in as super user
if (!isset($_SESSION['super_user_logged_in']) || $_SESSION['super_user_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

// Check session timeout (2 hours for super user)
if (isset($_SESSION['super_user_login_time']) && (time() - $_SESSION['super_user_login_time'] > 7200)) {
    session_destroy();
    header('Location: login.php?expired=1');
    exit;
}

require_once '../includes/config.php';

$conn = getDbConnection();

// Configuration
define('ROOT_PATH', dirname(__DIR__)); // System root folder
define('ALLOWED_EXTENSIONS', ['php', 'html', 'css', 'js', 'json', 'txt', 'md', 'sql', 'xml', 'htaccess', 'env', 'ini', 'yml', 'yaml']);
define('EDITABLE_EXTENSIONS', ['php', 'html', 'css', 'js', 'json', 'txt', 'md', 'sql', 'xml', 'htaccess', 'env', 'ini', 'yml', 'yaml']);
define('MAX_UPLOAD_SIZE', 50 * 1024 * 1024); // 50MB for super user

$error = '';
$success = '';
$current_path = ROOT_PATH;

// Handle download requests
if (isset($_GET['download']) && !empty($_GET['download'])) {
    $download_file = str_replace(['../', '..\\', '..'], '', $_GET['download']);
    $download_path = realpath(ROOT_PATH . DIRECTORY_SEPARATOR . $download_file);
    
    // Validate path is within ROOT_PATH
    if ($download_path && strpos($download_path, realpath(ROOT_PATH)) === 0) {
        if (is_file($download_path) && is_readable($download_path)) {
            // Download single file
            $filename = basename($download_path);
            $filesize = filesize($download_path);
            
            // Clean any output buffers
            while (ob_get_level()) {
                ob_end_clean();
            }
            
            // Set headers for download
            header('Content-Description: File Transfer');
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Transfer-Encoding: binary');
            header('Expires: 0');
            header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
            header('Pragma: public');
            header('Content-Length: ' . $filesize);
            
            // Output file
            readfile($download_path);
            exit;
        } elseif (is_dir($download_path)) {
            // Download folder as ZIP - check if ZipArchive is available
            if (!class_exists('ZipArchive')) {
                $_SESSION['fm_error'] = 'ZIP functionality is not available. Please enable the PHP zip extension.';
                header('Location: file_manager.php?path=' . urlencode($_GET['path'] ?? ''));
                exit;
            }
            $zip_name = basename($download_path) . '_' . date('Y-m-d_H-i-s') . '.zip';
            $zip_path = sys_get_temp_dir() . '/' . $zip_name;
            
            $zip = new ZipArchive();
            if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {
                $files = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($download_path, RecursiveDirectoryIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::LEAVES_ONLY
                );
                
                foreach ($files as $file) {
                    if (!$file->isDir()) {
                        $file_path = $file->getRealPath();
                        $relative_path = substr($file_path, strlen($download_path) + 1);
                        $zip->addFile($file_path, $relative_path);
                    }
                }
                $zip->close();
                
                // Clean any output buffers
                while (ob_get_level()) {
                    ob_end_clean();
                }
                
                header('Content-Description: File Transfer');
                header('Content-Type: application/zip');
                header('Content-Disposition: attachment; filename="' . $zip_name . '"');
                header('Content-Transfer-Encoding: binary');
                header('Expires: 0');
                header('Cache-Control: must-revalidate');
                header('Pragma: public');
                header('Content-Length: ' . filesize($zip_path));
                readfile($zip_path);
                unlink($zip_path);
                exit;
            } else {
                $_SESSION['fm_error'] = 'Failed to create ZIP file.';
            }
        }
    } else {
        $_SESSION['fm_error'] = 'Invalid file path or file not readable.';
    }
    header('Location: file_manager.php?path=' . urlencode($_GET['path'] ?? ''));
    exit;
}

// Handle system backup
if (isset($_GET['backup']) && $_GET['backup'] === 'system') {
    // Check if ZipArchive is available
    if (!class_exists('ZipArchive')) {
        $_SESSION['fm_error'] = 'ZIP functionality is not available. Please enable the PHP zip extension.';
        header('Location: file_manager.php');
        exit;
    }
    
    $zip_name = 'vle_full_backup_' . date('Y-m-d_H-i-s') . '.zip';
    $zip_path = sys_get_temp_dir() . '/' . $zip_name;
    
    // Excluded folders for backup
    $exclude_folders = ['vendor', 'node_modules', '.git'];
    
    $zip = new ZipArchive();
    if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {
        $files = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator(ROOT_PATH, RecursiveDirectoryIterator::SKIP_DOTS),
                function ($current, $key, $iterator) use ($exclude_folders) {
                    if ($current->isDir()) {
                        return !in_array($current->getFilename(), $exclude_folders);
                    }
                    return true;
                }
            ),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        
        $file_count = 0;
        foreach ($files as $file) {
            if (!$file->isDir()) {
                $file_path = $file->getRealPath();
                $relative_path = substr($file_path, strlen(ROOT_PATH) + 1);
                $zip->addFile($file_path, $relative_path);
                $file_count++;
            }
        }
        $zip->close();
        
        // Log the backup
        error_log("Super User Backup: Full system backup by {$_SESSION['super_user_username']} from {$_SERVER['REMOTE_ADDR']}");
        
        // Clean any output buffers
        while (ob_get_level()) {
            ob_end_clean();
        }
        
        header('Content-Description: File Transfer');
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $zip_name . '"');
        header('Content-Transfer-Encoding: binary');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($zip_path));
        readfile($zip_path);
        unlink($zip_path);
        exit;
    } else {
        $_SESSION['fm_error'] = 'Failed to create backup ZIP file.';
    }
    header('Location: file_manager.php');
    exit;
}

// Get current directory
$path = isset($_GET['path']) ? $_GET['path'] : '';
$path = str_replace(['../', '..\\'], '', $path); // Prevent directory traversal
$current_path = realpath(ROOT_PATH . '/' . $path);

// Ensure we stay within ROOT_PATH
if ($current_path === false || strpos($current_path, ROOT_PATH) !== 0) {
    $current_path = ROOT_PATH;
    $path = '';
}

// Handle file operations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Create folder
    if (isset($_POST['create_folder']) && !empty($_POST['folder_name'])) {
        $folder_name = preg_replace('/[^a-zA-Z0-9_-]/', '', $_POST['folder_name']);
        $new_folder = $current_path . '/' . $folder_name;
        if (!file_exists($new_folder)) {
            if (mkdir($new_folder, 0755)) {
                $success = "Folder '$folder_name' created successfully.";
                error_log("Super User: Created folder '$folder_name' by {$_SESSION['super_user_username']}");
            } else {
                $error = "Failed to create folder.";
            }
        } else {
            $error = "Folder already exists.";
        }
    }
    
    // Create file
    if (isset($_POST['create_file']) && !empty($_POST['file_name'])) {
        $file_name = preg_replace('/[^a-zA-Z0-9_.-]/', '', $_POST['file_name']);
        $new_file = $current_path . '/' . $file_name;
        if (!file_exists($new_file)) {
            if (file_put_contents($new_file, $_POST['file_content'] ?? '') !== false) {
                $success = "File '$file_name' created successfully.";
                error_log("Super User: Created file '$file_name' by {$_SESSION['super_user_username']}");
            } else {
                $error = "Failed to create file.";
            }
        } else {
            $error = "File already exists.";
        }
    }
    
    // Upload file(s)
    if (isset($_FILES['upload_file']) && !empty($_FILES['upload_file']['name'][0])) {
        $uploaded_count = 0;
        $failed_count = 0;
        
        foreach ($_FILES['upload_file']['name'] as $key => $name) {
            if ($_FILES['upload_file']['error'][$key] === UPLOAD_ERR_OK) {
                $upload_name = basename($name);
                $upload_path = $current_path . '/' . $upload_name;
                
                if ($_FILES['upload_file']['size'][$key] <= MAX_UPLOAD_SIZE) {
                    if (move_uploaded_file($_FILES['upload_file']['tmp_name'][$key], $upload_path)) {
                        $uploaded_count++;
                    } else {
                        $failed_count++;
                    }
                } else {
                    $failed_count++;
                }
            }
        }
        
        if ($uploaded_count > 0) {
            $success = "$uploaded_count file(s) uploaded successfully." . ($failed_count > 0 ? " $failed_count failed." : "");
            error_log("Super User: Uploaded $uploaded_count files by {$_SESSION['super_user_username']}");
        } else {
            $error = "Failed to upload files.";
        }
    }
    
    // Upload and extract ZIP
    if (isset($_FILES['upload_zip']) && $_FILES['upload_zip']['error'] === UPLOAD_ERR_OK) {
        // Check if ZipArchive is available
        if (!class_exists('ZipArchive')) {
            $error = "ZIP functionality is not available. Please enable the PHP zip extension.";
        } else {
            $zip_name = basename($_FILES['upload_zip']['name']);
            $zip_ext = strtolower(pathinfo($zip_name, PATHINFO_EXTENSION));
            
            if ($zip_ext === 'zip') {
                if ($_FILES['upload_zip']['size'] <= MAX_UPLOAD_SIZE) {
                    $zip_temp = $_FILES['upload_zip']['tmp_name'];
                    $zip = new ZipArchive();
                    
                    if ($zip->open($zip_temp) === TRUE) {
                        $extract_to = $current_path;
                        if (isset($_POST['extract_to_folder']) && $_POST['extract_to_folder'] === '1') {
                            $folder_name = pathinfo($zip_name, PATHINFO_FILENAME);
                            $extract_to = $current_path . '/' . preg_replace('/[^a-zA-Z0-9_-]/', '', $folder_name);
                            if (!is_dir($extract_to)) {
                                mkdir($extract_to, 0755, true);
                            }
                        }
                        
                        $zip->extractTo($extract_to);
                        $extracted_count = $zip->numFiles;
                        $zip->close();
                        $success = "ZIP extracted successfully. $extracted_count items extracted.";
                        error_log("Super User: Extracted ZIP '$zip_name' ($extracted_count files) by {$_SESSION['super_user_username']}");
                    } else {
                        $error = "Failed to open ZIP file.";
                    }
                } else {
                $error = "ZIP file too large. Maximum size is " . (MAX_UPLOAD_SIZE / 1024 / 1024) . "MB.";
            }
        } else {
            $error = "Only ZIP files are supported for extraction.";
        }
        } // End of ZipArchive check else block
    }
    
    // Upload folder (multiple files with paths)
    if (isset($_FILES['upload_folder']) && !empty($_FILES['upload_folder']['name'][0])) {
        $uploaded_count = 0;
        $failed_count = 0;
        
        foreach ($_FILES['upload_folder']['name'] as $key => $name) {
            if ($_FILES['upload_folder']['error'][$key] === UPLOAD_ERR_OK) {
                $relative_path = isset($_POST['folder_paths'][$key]) ? $_POST['folder_paths'][$key] : $name;
                $upload_name = basename($relative_path);
                $dir_path = dirname($relative_path);
                
                $target_dir = $current_path;
                if ($dir_path && $dir_path !== '.') {
                    $target_dir = $current_path . '/' . $dir_path;
                    if (!is_dir($target_dir)) {
                        mkdir($target_dir, 0755, true);
                    }
                }
                
                $upload_path = $target_dir . '/' . $upload_name;
                
                if ($_FILES['upload_folder']['size'][$key] <= MAX_UPLOAD_SIZE) {
                    if (move_uploaded_file($_FILES['upload_folder']['tmp_name'][$key], $upload_path)) {
                        $uploaded_count++;
                    } else {
                        $failed_count++;
                    }
                } else {
                    $failed_count++;
                }
            }
        }
        
        if ($uploaded_count > 0) {
            $success = "Folder uploaded: $uploaded_count file(s) successfully." . ($failed_count > 0 ? " $failed_count failed." : "");
        } else {
            $error = "Failed to upload folder.";
        }
    }
    
    // Save edited file
    if (isset($_POST['save_file']) && isset($_POST['file_path']) && isset($_POST['file_content'])) {
        $edit_path = realpath($_POST['file_path']);
        if ($edit_path && strpos($edit_path, ROOT_PATH) === 0) {
            if (file_put_contents($edit_path, $_POST['file_content']) !== false) {
                $success = "File saved successfully.";
                error_log("Super User: Edited file '$edit_path' by {$_SESSION['super_user_username']}");
            } else {
                $error = "Failed to save file.";
            }
        } else {
            $error = "Invalid file path.";
        }
    }
    
    // Delete file/folder
    if (isset($_POST['delete_item']) && isset($_POST['item_path'])) {
        $delete_path = realpath($_POST['item_path']);
        if ($delete_path && strpos($delete_path, ROOT_PATH) === 0 && $delete_path !== ROOT_PATH) {
            if (is_dir($delete_path)) {
                // Recursive delete for super user
                function deleteDirectory($dir) {
                    if (!is_dir($dir)) return false;
                    $items = array_diff(scandir($dir), ['.', '..']);
                    foreach ($items as $item) {
                        $path = $dir . '/' . $item;
                        is_dir($path) ? deleteDirectory($path) : unlink($path);
                    }
                    return rmdir($dir);
                }
                
                if (deleteDirectory($delete_path)) {
                    $success = "Folder and contents deleted successfully.";
                    error_log("Super User: Deleted folder '$delete_path' by {$_SESSION['super_user_username']}");
                } else {
                    $error = "Failed to delete folder.";
                }
            } else {
                if (unlink($delete_path)) {
                    $success = "File deleted successfully.";
                    error_log("Super User: Deleted file '$delete_path' by {$_SESSION['super_user_username']}");
                } else {
                    $error = "Failed to delete file.";
                }
            }
        } else {
            $error = "Invalid path or cannot delete root.";
        }
    }
    
    // Rename file/folder
    if (isset($_POST['rename_item']) && isset($_POST['old_path']) && isset($_POST['new_name'])) {
        $old_path = realpath($_POST['old_path']);
        $new_name = preg_replace('/[^a-zA-Z0-9_.-]/', '', $_POST['new_name']);
        if ($old_path && strpos($old_path, ROOT_PATH) === 0) {
            $new_path = dirname($old_path) . '/' . $new_name;
            if (!file_exists($new_path)) {
                if (rename($old_path, $new_path)) {
                    $success = "Renamed successfully.";
                    error_log("Super User: Renamed '$old_path' to '$new_name' by {$_SESSION['super_user_username']}");
                } else {
                    $error = "Failed to rename.";
                }
            } else {
                $error = "A file/folder with that name already exists.";
            }
        } else {
            $error = "Invalid path.";
        }
    }
}

// Get directory contents
$items = [];
if (is_dir($current_path)) {
    $files = scandir($current_path);
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') continue;
        
        $file_path = $current_path . '/' . $file;
        $is_dir = is_dir($file_path);
        $ext = $is_dir ? '' : strtolower(pathinfo($file, PATHINFO_EXTENSION));
        
        $items[] = [
            'name' => $file,
            'path' => $file_path,
            'relative_path' => str_replace(ROOT_PATH . '/', '', $file_path),
            'is_dir' => $is_dir,
            'size' => $is_dir ? '-' : filesize($file_path),
            'modified' => filemtime($file_path),
            'extension' => $ext,
            'editable' => in_array($ext, EDITABLE_EXTENSIONS),
            'icon' => $is_dir ? 'bi-folder-fill text-warning' : getFileIcon($ext)
        ];
    }
    
    // Sort: directories first, then files
    usort($items, function($a, $b) {
        if ($a['is_dir'] && !$b['is_dir']) return -1;
        if (!$a['is_dir'] && $b['is_dir']) return 1;
        return strcasecmp($a['name'], $b['name']);
    });
}

// Calculate breadcrumbs
$breadcrumbs = [];
$path_parts = $path ? explode('/', $path) : [];
$breadcrumb_path = '';
foreach ($path_parts as $part) {
    $breadcrumb_path .= ($breadcrumb_path ? '/' : '') . $part;
    $breadcrumbs[] = ['name' => $part, 'path' => $breadcrumb_path];
}

function getFileIcon($ext) {
    $icons = [
        'php' => 'bi-file-earmark-code text-purple',
        'html' => 'bi-filetype-html text-orange',
        'css' => 'bi-filetype-css text-info',
        'js' => 'bi-filetype-js text-warning',
        'json' => 'bi-filetype-json text-success',
        'txt' => 'bi-file-earmark-text text-secondary',
        'md' => 'bi-markdown text-dark',
        'sql' => 'bi-database text-primary',
        'xml' => 'bi-filetype-xml text-danger',
        'jpg' => 'bi-file-earmark-image text-success',
        'jpeg' => 'bi-file-earmark-image text-success',
        'png' => 'bi-file-earmark-image text-success',
        'gif' => 'bi-file-earmark-image text-success',
        'pdf' => 'bi-file-earmark-pdf text-danger',
        'zip' => 'bi-file-earmark-zip text-warning',
    ];
    return $icons[$ext] ?? 'bi-file-earmark text-secondary';
}

function formatSize($bytes) {
    if ($bytes === '-') return '-';
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return round($bytes / 1024, 2) . ' KB';
    return round($bytes / 1048576, 2) . ' MB';
}

$page_title = 'System File Manager';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title ?> - Super User</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/codemirror.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/theme/dracula.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/theme/monokai.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/theme/material.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/addon/dialog/dialog.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/addon/fold/foldgutter.min.css" rel="stylesheet">
    <style>
        :root {
            --primary: #1a1a2e;
            --secondary: #16213e;
            --accent: #e94560;
        }
        body { font-family: 'Inter', sans-serif; background: #0f0f1a; color: #eee; min-height: 100vh; }
        .navbar { background: linear-gradient(135deg, var(--primary), var(--secondary)); }
        .card { background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); }
        .card-header { background: rgba(255,255,255,0.08); border-bottom: 1px solid rgba(255,255,255,0.1); }
        .table { color: #eee; }
        .table-light { background: rgba(255,255,255,0.08) !important; color: #eee !important; }
        .table-hover tbody tr:hover { background: rgba(255,255,255,0.1); }
        .file-item { transition: background 0.2s; }
        .file-item:hover { background: rgba(233, 69, 96, 0.1) !important; }
        .file-item[ondblclick]:hover { background: rgba(233, 69, 96, 0.2) !important; }
        .text-purple { color: #a855f7; }
        .text-orange { color: #fb923c; }
        .breadcrumb-nav { background: rgba(255,255,255,0.08); border-radius: 0.5rem; padding: 0.75rem 1rem; }
        .breadcrumb-nav a { color: #e94560; }
        .CodeMirror { height: 500px; border: 1px solid rgba(255,255,255,0.2); border-radius: 0.375rem; }
        .CodeMirror-fullscreen { position: fixed !important; top: 0; left: 0; right: 0; bottom: 0; height: auto !important; z-index: 9999; }
        .editor-toolbar { background: #1a1a2e; padding: 8px; border-radius: 0.375rem 0.375rem 0 0; }
        .warning-banner { background: linear-gradient(135deg, #e94560, #c73a52); }
        .btn-accent { background: var(--accent); border-color: var(--accent); color: white; }
        .btn-accent:hover { background: #c73a52; border-color: #c73a52; color: white; }
        .form-control, .form-select { background: rgba(255,255,255,0.08); border-color: rgba(255,255,255,0.2); color: #eee; }
        .form-control:focus, .form-select:focus { background: rgba(255,255,255,0.12); border-color: var(--accent); color: #eee; box-shadow: 0 0 0 0.2rem rgba(233, 69, 96, 0.25); }
        .form-control::placeholder { color: rgba(255,255,255,0.5); }
        .alert { border: none; }
        .modal-content { background: #1a1a2e; border: 1px solid rgba(255,255,255,0.1); }
        .modal-header { border-bottom-color: rgba(255,255,255,0.1); }
        .modal-footer { border-top-color: rgba(255,255,255,0.1); }
        .btn-close { filter: brightness(0) invert(1); }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark mb-4">
        <div class="container-fluid">
            <a class="navbar-brand d-flex align-items-center" href="dashboard.php">
                <i class="bi bi-shield-lock-fill me-2 text-danger"></i>
                <span class="fw-bold">Super User</span>
            </a>
            <div class="d-flex align-items-center">
                <span class="text-light me-3">
                    <i class="bi bi-person-circle me-1"></i>
                    <?= htmlspecialchars($_SESSION['super_user_name'] ?? $_SESSION['super_user_username']) ?>
                </span>
                <a href="logout.php" class="btn btn-outline-light btn-sm">
                    <i class="bi bi-box-arrow-right me-1"></i>Logout
                </a>
            </div>
        </div>
    </nav>
    
    <div class="container-fluid py-2">
        <!-- Warning Banner -->
        <div class="warning-banner text-white p-2 mb-4 rounded">
            <div class="d-flex justify-content-between align-items-center">
                <span><i class="bi bi-exclamation-triangle me-2"></i><strong>SUPER USER MODE:</strong> Full system access enabled. All actions are logged.</span>
                <a href="dashboard.php" class="btn btn-outline-light btn-sm"><i class="bi bi-speedometer2 me-1"></i>Dashboard</a>
            </div>
        </div>
        
        <?php 
        // Check for session error
        if (isset($_SESSION['fm_error'])) {
            $error = $_SESSION['fm_error'];
            unset($_SESSION['fm_error']);
        }
        ?>
        
        <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show"><?= htmlspecialchars($error) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        
        <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show"><?= htmlspecialchars($success) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        
        <div class="row">
            <!-- Sidebar -->
            <div class="col-md-3">
                <div class="card mb-4">
                    <div class="card-header text-white">
                        <i class="bi bi-plus-circle me-2"></i>Actions
                    </div>
                    <div class="card-body">
                        <!-- Create Folder -->
                        <form method="POST" class="mb-3">
                            <label class="form-label small text-muted">New Folder</label>
                            <div class="input-group input-group-sm">
                                <input type="text" name="folder_name" class="form-control" placeholder="folder_name" pattern="[a-zA-Z0-9_-]+" required>
                                <button type="submit" name="create_folder" class="btn btn-outline-info"><i class="bi bi-folder-plus"></i></button>
                            </div>
                        </form>
                        
                        <!-- Upload File -->
                        <form method="POST" enctype="multipart/form-data" class="mb-3">
                            <label class="form-label small text-muted">Upload Files</label>
                            <div class="input-group input-group-sm">
                                <input type="file" name="upload_file[]" class="form-control" multiple required>
                                <button type="submit" class="btn btn-outline-success" title="Upload"><i class="bi bi-upload"></i></button>
                            </div>
                            <small class="text-muted">Max: <?= MAX_UPLOAD_SIZE / 1024 / 1024 ?>MB each</small>
                        </form>
                        
                        <!-- Upload ZIP -->
                        <form method="POST" enctype="multipart/form-data" class="mb-3">
                            <label class="form-label small text-muted">Upload & Extract ZIP</label>
                            <div class="input-group input-group-sm">
                                <input type="file" name="upload_zip" class="form-control" accept=".zip" required>
                                <button type="submit" class="btn btn-outline-warning" title="Extract ZIP"><i class="bi bi-file-earmark-zip"></i></button>
                            </div>
                            <div class="form-check mt-1">
                                <input type="checkbox" name="extract_to_folder" value="1" class="form-check-input" id="extractToFolder" checked>
                                <label class="form-check-label small text-muted" for="extractToFolder">Extract to subfolder</label>
                            </div>
                        </form>
                        
                        <!-- Upload Folder -->
                        <form method="POST" enctype="multipart/form-data" class="mb-3" id="folderUploadForm">
                            <label class="form-label small text-muted">Upload Folder</label>
                            <div class="input-group input-group-sm">
                                <input type="file" name="upload_folder[]" class="form-control" id="folderInput" webkitdirectory directory multiple required>
                                <button type="submit" class="btn btn-outline-primary" title="Upload Folder"><i class="bi bi-folder-plus"></i></button>
                            </div>
                            <div id="folderPaths"></div>
                        </form>
                        
                        <hr class="border-secondary my-2">
                        
                        <!-- Create File -->
                        <button class="btn btn-outline-info btn-sm w-100 mb-2" data-bs-toggle="modal" data-bs-target="#createFileModal">
                            <i class="bi bi-file-earmark-plus me-1"></i>Create New File
                        </button>
                        
                        <hr class="border-secondary my-2">
                        
                        <!-- System Backup -->
                        <a href="?backup=system" class="btn btn-accent btn-sm w-100" onclick="return confirm('Download full system backup?\\nThis may take a while for large systems.');">
                            <i class="bi bi-cloud-download me-1"></i>Full System Backup
                        </a>
                    </div>
                </div>
                
                <div class="card">
                    <div class="card-header">
                        <i class="bi bi-info-circle me-2"></i>Info
                    </div>
                    <div class="card-body small">
                        <p class="mb-2"><strong>Current Path:</strong><br><code class="text-info text-break"><?= htmlspecialchars(str_replace(ROOT_PATH, '[ROOT]', $current_path)) ?></code></p>
                        <p class="mb-2"><strong>Items:</strong> <?= count($items) ?></p>
                        <p class="mb-0"><strong>User:</strong> <?= htmlspecialchars($_SESSION['super_user_username']) ?></p>
                    </div>
                </div>
            </div>
            
            <!-- Main Content -->
            <div class="col-md-9">
                <div class="card">
                    <div class="card-header">
                        <!-- Breadcrumb -->
                        <nav class="breadcrumb-nav mb-0">
                            <ol class="breadcrumb mb-0">
                                <li class="breadcrumb-item">
                                    <a href="?path="><i class="bi bi-house me-1"></i>Root</a>
                                </li>
                                <?php foreach ($breadcrumbs as $bc): ?>
                                <li class="breadcrumb-item">
                                    <a href="?path=<?= urlencode($bc['path']) ?>"><?= htmlspecialchars($bc['name']) ?></a>
                                </li>
                                <?php endforeach; ?>
                            </ol>
                        </nav>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width: 40px;"></th>
                                        <th>Name</th>
                                        <th style="width: 100px;">Size</th>
                                        <th style="width: 150px;">Modified</th>
                                        <th style="width: 180px;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($path): ?>
                                    <tr class="file-item">
                                        <td><i class="bi bi-arrow-up text-muted"></i></td>
                                        <td colspan="4">
                                            <a href="?path=<?= urlencode(dirname($path)) ?>" class="text-decoration-none text-info">..</a>
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                    
                                    <?php foreach ($items as $item): ?>
                                    <tr class="file-item" <?php if ($item['editable']): ?>ondblclick="editFile('<?= htmlspecialchars($item['path'], ENT_QUOTES) ?>', '<?= htmlspecialchars($item['name'], ENT_QUOTES) ?>')" style="cursor: pointer;" title="Double-click to edit"<?php endif; ?>>
                                        <td><i class="bi <?= $item['icon'] ?>"></i></td>
                                        <td>
                                            <?php if ($item['is_dir']): ?>
                                            <a href="?path=<?= urlencode($item['relative_path']) ?>" class="text-decoration-none fw-medium text-warning">
                                                <?= htmlspecialchars($item['name']) ?>
                                            </a>
                                            <?php elseif ($item['editable']): ?>
                                            <span class="fw-medium text-info" style="cursor: pointer;" ondblclick="editFile('<?= htmlspecialchars($item['path'], ENT_QUOTES) ?>', '<?= htmlspecialchars($item['name'], ENT_QUOTES) ?>')"><?= htmlspecialchars($item['name']) ?></span>
                                            <small class="text-muted ms-2"><i class="bi bi-pencil-square"></i></small>
                                            <?php else: ?>
                                            <span class="fw-medium"><?= htmlspecialchars($item['name']) ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td><small class="text-muted"><?= formatSize($item['size']) ?></small></td>
                                        <td><small class="text-muted"><?= date('M j, Y H:i', $item['modified']) ?></small></td>
                                        <td>
                                            <div class="btn-group btn-group-sm">
                                                <a href="?download=<?= urlencode($item['relative_path']) ?>&path=<?= urlencode($path) ?>" class="btn btn-outline-success" title="Download<?= $item['is_dir'] ? ' as ZIP' : '' ?>">
                                                    <i class="bi bi-download"></i>
                                                </a>
                                                <?php if ($item['editable']): ?>
                                                <button class="btn btn-outline-primary" onclick="editFile('<?= htmlspecialchars($item['path'], ENT_QUOTES) ?>', '<?= htmlspecialchars($item['name'], ENT_QUOTES) ?>')" title="Edit">
                                                    <i class="bi bi-pencil"></i>
                                                </button>
                                                <?php endif; ?>
                                                <button class="btn btn-outline-secondary" onclick="renameItem('<?= htmlspecialchars($item['path'], ENT_QUOTES) ?>', '<?= htmlspecialchars($item['name'], ENT_QUOTES) ?>')" title="Rename">
                                                    <i class="bi bi-input-cursor-text"></i>
                                                </button>
                                                <button class="btn btn-outline-danger" onclick="deleteItem('<?= htmlspecialchars($item['path'], ENT_QUOTES) ?>', '<?= htmlspecialchars($item['name'], ENT_QUOTES) ?>', <?= $item['is_dir'] ? 'true' : 'false' ?>)" title="Delete">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    
                                    <?php if (empty($items)): ?>
                                    <tr>
                                        <td colspan="5" class="text-center py-4 text-muted">
                                            <i class="bi bi-folder2-open display-4"></i>
                                            <p class="mt-2 mb-0">This folder is empty</p>
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Create File Modal -->
        <div class="modal fade" id="createFileModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form method="POST">
                        <div class="modal-header">
                            <h5 class="modal-title"><i class="bi bi-file-earmark-plus me-2"></i>Create New File</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <div class="mb-3">
                                <label class="form-label">File Name</label>
                                <input type="text" name="file_name" class="form-control" placeholder="filename.php" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Initial Content (optional)</label>
                                <textarea name="file_content" class="form-control" rows="5" placeholder="<?php // Your code here ?>"></textarea>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" name="create_file" class="btn btn-primary">Create File</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        
        <!-- Edit File Modal -->
        <div class="modal fade" id="editFileModal" tabindex="-1">
            <div class="modal-dialog modal-xl modal-dialog-scrollable">
                <div class="modal-content">
                    <form method="POST" id="editForm">
                        <div class="modal-header" style="background: var(--primary);">
                            <h5 class="modal-title text-white"><i class="bi bi-code-slash me-2"></i>Edit: <span id="editFileName" class="text-info"></span></h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body p-0">
                            <input type="hidden" name="file_path" id="editFilePath">
                            <textarea name="file_content" id="editFileContent" style="display:none;"></textarea>
                            
                            <!-- Editor Toolbar -->
                            <div class="editor-toolbar d-flex justify-content-between align-items-center">
                                <div class="btn-group btn-group-sm">
                                    <button type="button" class="btn btn-outline-light" onclick="editorUndo()" title="Undo (Ctrl+Z)">
                                        <i class="bi bi-arrow-counterclockwise"></i>
                                    </button>
                                    <button type="button" class="btn btn-outline-light" onclick="editorRedo()" title="Redo (Ctrl+Y)">
                                        <i class="bi bi-arrow-clockwise"></i>
                                    </button>
                                    <button type="button" class="btn btn-outline-light" onclick="editorSearch()" title="Find (Ctrl+F)">
                                        <i class="bi bi-search"></i>
                                    </button>
                                    <button type="button" class="btn btn-outline-light" onclick="editorReplace()" title="Replace (Ctrl+H)">
                                        <i class="bi bi-arrow-left-right"></i>
                                    </button>
                                </div>
                                <div class="d-flex align-items-center gap-2">
                                    <select id="editorTheme" class="form-select form-select-sm" style="width: auto;" onchange="changeTheme(this.value)">
                                        <option value="dracula">Dracula</option>
                                        <option value="monokai">Monokai</option>
                                        <option value="material">Material</option>
                                    </select>
                                    <button type="button" class="btn btn-outline-light btn-sm" onclick="toggleFullscreen()" title="Fullscreen (F11)">
                                        <i class="bi bi-arrows-fullscreen"></i>
                                    </button>
                                </div>
                            </div>
                            
                            <div id="codeEditor"></div>
                            
                            <!-- Status Bar -->
                            <div style="background: var(--primary);" class="text-white px-3 py-1 d-flex justify-content-between small">
                                <span>Line: <span id="cursorLine">1</span>, Column: <span id="cursorCol">1</span></span>
                                <span id="fileMode">Plain Text</span>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <span class="text-muted me-auto small"><i class="bi bi-info-circle me-1"></i>Ctrl+S to save</span>
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" name="save_file" class="btn btn-success"><i class="bi bi-save me-1"></i>Save Changes</button>
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
                        <div class="modal-header">
                            <h5 class="modal-title"><i class="bi bi-input-cursor-text me-2"></i>Rename</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <input type="hidden" name="old_path" id="renameOldPath">
                            <div class="mb-3">
                                <label class="form-label">New Name</label>
                                <input type="text" name="new_name" id="renameNewName" class="form-control" required>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" name="rename_item" class="btn btn-primary">Rename</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        
        <!-- Delete Modal -->
        <div class="modal fade" id="deleteModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form method="POST">
                        <div class="modal-header bg-danger">
                            <h5 class="modal-title text-white"><i class="bi bi-exclamation-triangle me-2"></i>Confirm Delete</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <input type="hidden" name="item_path" id="deleteItemPath">
                            <p>Are you sure you want to delete <strong id="deleteItemName" class="text-danger"></strong>?</p>
                            <p class="text-warning mb-0"><i class="bi bi-exclamation-circle me-1"></i>Super User: Folders will be deleted recursively!</p>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" name="delete_item" class="btn btn-danger">Delete</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/codemirror.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/mode/php/php.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/mode/javascript/javascript.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/mode/css/css.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/mode/xml/xml.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/mode/htmlmixed/htmlmixed.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/mode/clike/clike.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/mode/sql/sql.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/mode/markdown/markdown.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/addon/dialog/dialog.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/addon/search/search.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/addon/search/searchcursor.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/addon/edit/matchbrackets.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/addon/edit/closebrackets.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/addon/fold/foldcode.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/addon/fold/foldgutter.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/addon/fold/brace-fold.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/addon/selection/active-line.min.js"></script>
    <script>
        let editor = null;
        let isFullscreen = false;
        
        const modeMap = {
            'php': { mode: 'application/x-httpd-php', name: 'PHP' },
            'js': { mode: 'javascript', name: 'JavaScript' },
            'css': { mode: 'css', name: 'CSS' },
            'html': { mode: 'htmlmixed', name: 'HTML' },
            'htm': { mode: 'htmlmixed', name: 'HTML' },
            'json': { mode: 'application/json', name: 'JSON' },
            'xml': { mode: 'xml', name: 'XML' },
            'sql': { mode: 'text/x-sql', name: 'SQL' },
            'md': { mode: 'markdown', name: 'Markdown' },
            'txt': { mode: 'text/plain', name: 'Plain Text' },
            'htaccess': { mode: 'text/plain', name: 'htaccess' },
            'env': { mode: 'text/plain', name: 'Environment' }
        };
        
        function editFile(path, name) {
            document.getElementById('editFilePath').value = path;
            document.getElementById('editFileName').textContent = name;
            
            const modal = new bootstrap.Modal(document.getElementById('editFileModal'));
            
            fetch('get_file_content.php?path=' + encodeURIComponent(path))
                .then(response => {
                    if (!response.ok) throw new Error('Failed to load file');
                    return response.text();
                })
                .then(content => {
                    if (editor) editor.toTextArea();
                    
                    document.getElementById('editFileContent').value = content;
                    
                    const ext = name.split('.').pop().toLowerCase();
                    const modeInfo = modeMap[ext] || { mode: 'text/plain', name: 'Plain Text' };
                    document.getElementById('fileMode').textContent = modeInfo.name;
                    
                    editor = CodeMirror.fromTextArea(document.getElementById('editFileContent'), {
                        mode: modeInfo.mode,
                        theme: 'dracula',
                        lineNumbers: true,
                        indentUnit: 4,
                        tabSize: 4,
                        lineWrapping: true,
                        matchBrackets: true,
                        autoCloseBrackets: true,
                        styleActiveLine: true,
                        foldGutter: true,
                        gutters: ['CodeMirror-linenumbers', 'CodeMirror-foldgutter'],
                        extraKeys: {
                            'Ctrl-S': function(cm) { document.getElementById('editForm').requestSubmit(); },
                            'Ctrl-F': function(cm) { CodeMirror.commands.find(cm); },
                            'Ctrl-H': function(cm) { CodeMirror.commands.replace(cm); },
                            'F11': function(cm) { toggleFullscreen(); },
                            'Esc': function(cm) { if (isFullscreen) toggleFullscreen(); }
                        }
                    });
                    
                    editor.setValue(content);
                    editor.on('cursorActivity', function() {
                        const pos = editor.getCursor();
                        document.getElementById('cursorLine').textContent = pos.line + 1;
                        document.getElementById('cursorCol').textContent = pos.ch + 1;
                    });
                    
                    modal.show();
                    setTimeout(() => editor.refresh(), 200);
                })
                .catch(err => alert('Failed to load file content: ' + err.message));
        }
        
        function editorUndo() { if (editor) editor.undo(); }
        function editorRedo() { if (editor) editor.redo(); }
        function editorSearch() { if (editor) CodeMirror.commands.find(editor); }
        function editorReplace() { if (editor) CodeMirror.commands.replace(editor); }
        function changeTheme(theme) { if (editor) editor.setOption('theme', theme); }
        
        function toggleFullscreen() {
            if (!editor) return;
            const wrapper = editor.getWrapperElement();
            if (!isFullscreen) {
                wrapper.classList.add('CodeMirror-fullscreen');
                editor.setSize('100%', '100vh');
            } else {
                wrapper.classList.remove('CodeMirror-fullscreen');
                editor.setSize('100%', '500px');
            }
            isFullscreen = !isFullscreen;
            editor.refresh();
        }
        
        document.getElementById('editForm').addEventListener('submit', function(e) {
            if (editor) document.getElementById('editFileContent').value = editor.getValue();
        });
        
        function renameItem(path, name) {
            document.getElementById('renameOldPath').value = path;
            document.getElementById('renameNewName').value = name;
            new bootstrap.Modal(document.getElementById('renameModal')).show();
        }
        
        function deleteItem(path, name, isDir) {
            document.getElementById('deleteItemPath').value = path;
            document.getElementById('deleteItemName').textContent = name + (isDir ? ' (folder & all contents)' : '');
            new bootstrap.Modal(document.getElementById('deleteModal')).show();
        }
        
        document.getElementById('folderInput')?.addEventListener('change', function(e) {
            const pathsContainer = document.getElementById('folderPaths');
            pathsContainer.innerHTML = '';
            const files = this.files;
            for (let i = 0; i < files.length; i++) {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'folder_paths[' + i + ']';
                input.value = files[i].webkitRelativePath || files[i].name;
                pathsContainer.appendChild(input);
            }
            if (files.length > 0) {
                const info = document.createElement('small');
                info.className = 'text-muted';
                info.textContent = files.length + ' file(s) selected';
                pathsContainer.appendChild(info);
            }
        });
        
        document.getElementById('editFileModal').addEventListener('hidden.bs.modal', function() {
            if (isFullscreen) toggleFullscreen();
        });
    </script>
</body>
</html>
