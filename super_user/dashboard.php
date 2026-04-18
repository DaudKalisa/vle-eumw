<?php
/**
 * Super User Dashboard
 * Central hub for system administration tools
 */
session_start();

// Check if logged in as super user
if (!isset($_SESSION['super_user_logged_in']) || $_SESSION['super_user_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

// Check session timeout (2 hours)
if (isset($_SESSION['super_user_login_time']) && (time() - $_SESSION['super_user_login_time'] > 7200)) {
    session_destroy();
    header('Location: login.php?expired=1');
    exit;
}

require_once '../includes/config.php';
$conn = getDbConnection();

// Get system stats
$stats = [
    'files' => 0,
    'folders' => 0,
    'tables' => 0,
    'disk_free' => disk_free_space(dirname(__DIR__)),
    'disk_total' => disk_total_space(dirname(__DIR__))
];

// Count files and folders in root
$root = dirname(__DIR__);
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);
foreach ($iterator as $item) {
    if ($item->isDir()) {
        $stats['folders']++;
    } else {
        $stats['files']++;
    }
    if ($stats['files'] + $stats['folders'] > 10000) break; // Limit for performance
}

// Count database tables
try {
    $result = $conn->query("SHOW TABLES");
    $stats['tables'] = $result->num_rows;
} catch (Exception $e) {
    $stats['tables'] = '?';
}

$page_title = 'Super User Dashboard';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #1a1a2e;
            --secondary: #16213e;
            --accent: #e94560;
        }
        body { 
            font-family: 'Inter', sans-serif; 
            background: linear-gradient(135deg, #0f0f1a 0%, #1a1a2e 100%);
            color: #eee; 
            min-height: 100vh;
        }
        .navbar { background: linear-gradient(135deg, var(--primary), var(--secondary)); }
        .card { 
            background: rgba(255,255,255,0.05); 
            border: 1px solid rgba(255,255,255,0.1);
            transition: transform 0.3s, box-shadow 0.3s;
        }
        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(233, 69, 96, 0.2);
        }
        .card-header { background: rgba(255,255,255,0.08); border-bottom: 1px solid rgba(255,255,255,0.1); }
        .stat-card {
            background: linear-gradient(135deg, rgba(255,255,255,0.08), rgba(255,255,255,0.02));
        }
        .stat-card .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--accent);
        }
        .tool-card {
            cursor: pointer;
            min-height: 150px;
        }
        .tool-card:hover {
            background: rgba(233, 69, 96, 0.1);
        }
        .tool-icon {
            font-size: 3rem;
            color: var(--accent);
        }
        .warning-banner { background: linear-gradient(135deg, #e94560, #c73a52); }
        .btn-accent { 
            background: var(--accent); 
            border-color: var(--accent); 
            color: white; 
        }
        .btn-accent:hover { 
            background: #c73a52; 
            border-color: #c73a52; 
            color: white; 
        }
        .session-info {
            background: rgba(255,255,255,0.05);
            border-radius: 0.5rem;
            padding: 1rem;
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark mb-4">
        <div class="container-fluid">
            <a class="navbar-brand d-flex align-items-center" href="dashboard.php">
                <i class="bi bi-shield-lock-fill me-2 text-danger"></i>
                <span class="fw-bold">Super User Panel</span>
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
        <div class="warning-banner text-white p-3 mb-4 rounded">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h5 class="mb-1"><i class="bi bi-exclamation-triangle me-2"></i>SUPER USER MODE</h5>
                    <p class="mb-0">You have full system access. All actions are logged for security.</p>
                </div>
                <a href="../index.php" class="btn btn-outline-light">
                    <i class="bi bi-house me-1"></i>Back to Site
                </a>
            </div>
        </div>
        
        <!-- Stats Row -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card stat-card p-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="stat-value"><?= number_format($stats['files']) ?>+</div>
                            <div class="text-muted">System Files</div>
                        </div>
                        <i class="bi bi-file-earmark-code display-4 text-info opacity-50"></i>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stat-card p-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="stat-value"><?= number_format($stats['folders']) ?>+</div>
                            <div class="text-muted">Folders</div>
                        </div>
                        <i class="bi bi-folder display-4 text-warning opacity-50"></i>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stat-card p-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="stat-value"><?= $stats['tables'] ?></div>
                            <div class="text-muted">DB Tables</div>
                        </div>
                        <i class="bi bi-database display-4 text-success opacity-50"></i>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stat-card p-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="stat-value"><?= round(($stats['disk_total'] - $stats['disk_free']) / $stats['disk_total'] * 100) ?>%</div>
                            <div class="text-muted">Disk Used</div>
                        </div>
                        <i class="bi bi-hdd display-4 text-danger opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Tools Grid -->
        <h4 class="mb-3"><i class="bi bi-tools me-2"></i>System Tools</h4>
        <div class="row mb-4">
            <div class="col-md-4 mb-3">
                <a href="file_manager.php" class="text-decoration-none">
                    <div class="card tool-card p-4 text-center">
                        <i class="bi bi-folder-fill tool-icon mb-3"></i>
                        <h5>File Manager</h5>
                        <p class="text-muted mb-0">Browse, edit, upload, and manage all system files</p>
                    </div>
                </a>
            </div>
            <div class="col-md-4 mb-3">
                <a href="file_manager.php?backup=system" class="text-decoration-none" onclick="return confirm('Download full system backup?');">
                    <div class="card tool-card p-4 text-center">
                        <i class="bi bi-cloud-download tool-icon mb-3"></i>
                        <h5>System Backup</h5>
                        <p class="text-muted mb-0">Download complete system backup as ZIP</p>
                    </div>
                </a>
            </div>
            <div class="col-md-4 mb-3">
                <a href="../backup_database.php" class="text-decoration-none">
                    <div class="card tool-card p-4 text-center">
                        <i class="bi bi-database-down tool-icon mb-3"></i>
                        <h5>Database Backup</h5>
                        <p class="text-muted mb-0">Export database tables and data</p>
                    </div>
                </a>
            </div>
        </div>
        
        <!-- Session Info -->
        <div class="row">
            <div class="col-md-6">
                <div class="session-info">
                    <h6><i class="bi bi-info-circle me-2"></i>Session Information</h6>
                    <table class="table table-sm table-borderless text-muted mb-0">
                        <tr>
                            <td>Logged in as:</td>
                            <td class="text-info"><?= htmlspecialchars($_SESSION['super_user_username']) ?></td>
                        </tr>
                        <tr>
                            <td>Login time:</td>
                            <td><?= date('Y-m-d H:i:s', $_SESSION['super_user_login_time']) ?></td>
                        </tr>
                        <tr>
                            <td>Session expires:</td>
                            <td><?= date('Y-m-d H:i:s', $_SESSION['super_user_login_time'] + 7200) ?></td>
                        </tr>
                        <tr>
                            <td>IP Address:</td>
                            <td><?= htmlspecialchars($_SERVER['REMOTE_ADDR']) ?></td>
                        </tr>
                    </table>
                </div>
            </div>
            <div class="col-md-6">
                <div class="session-info">
                    <h6><i class="bi bi-server me-2"></i>Server Information</h6>
                    <table class="table table-sm table-borderless text-muted mb-0">
                        <tr>
                            <td>PHP Version:</td>
                            <td class="text-info"><?= PHP_VERSION ?></td>
                        </tr>
                        <tr>
                            <td>Server:</td>
                            <td><?= htmlspecialchars($_SERVER['SERVER_SOFTWARE'] ?? 'Unknown') ?></td>
                        </tr>
                        <tr>
                            <td>Disk Free:</td>
                            <td><?= round($stats['disk_free'] / 1073741824, 2) ?> GB</td>
                        </tr>
                        <tr>
                            <td>Disk Total:</td>
                            <td><?= round($stats['disk_total'] / 1073741824, 2) ?> GB</td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
