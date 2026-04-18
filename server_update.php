<?php
/**
 * VLE Server Update Script — Run on production to apply updates
 * 
 * Usage: Visit https://your-domain/server_update.php in browser
 * 
 * Security:
 *   - Password protected (hashed)
 *   - Rate-limited login attempts
 *   - Creates backup before applying
 *   - Detailed logging
 *   - Auto-locks after 5 failed attempts
 */

session_start();

// ===== CONFIGURATION =====
// IMPORTANT: Change this password hash before first use!
// Generate a new hash by visiting: server_update.php?generate_hash=YOUR_PASSWORD
$UPDATE_PASSWORD_HASH = '$2y$10$3xVtmeji48.mbBWj4f4.Tue/G4vE.h36/sbctl3Rv4EAv8o8COmgu';

// Max failed login attempts before lockout
$MAX_ATTEMPTS = 5;
$LOCKOUT_MINUTES = 30;
$LOG_FILE = __DIR__ . '/logs/update_log.txt';

// ===== HASH GENERATOR =====
if (isset($_GET['generate_hash']) && (strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') !== false || php_sapi_name() === 'cli')) {
    $pw = $_GET['generate_hash'];
    $hash = password_hash($pw, PASSWORD_BCRYPT);
    header('Content-Type: text/plain');
    echo "Your password hash (replace \$UPDATE_PASSWORD_HASH in server_update.php):\n\n";
    echo $hash . "\n";
    exit;
}

// ===== HELPER FUNCTIONS =====
function writeLog($message) {
    global $LOG_FILE;
    $dir = dirname($LOG_FILE);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $timestamp = date('Y-m-d H:i:s');
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    file_put_contents($LOG_FILE, "[$timestamp] [$ip] $message\n", FILE_APPEND | LOCK_EX);
}

function getAttemptFile() {
    return sys_get_temp_dir() . '/vle_update_attempts_' . md5($_SERVER['REMOTE_ADDR'] ?? 'unknown') . '.json';
}

function getAttempts() {
    $file = getAttemptFile();
    if (!file_exists($file)) return ['count' => 0, 'last' => 0];
    $data = json_decode(file_get_contents($file), true);
    return $data ?: ['count' => 0, 'last' => 0];
}

function recordAttempt() {
    $attempts = getAttempts();
    $attempts['count']++;
    $attempts['last'] = time();
    file_put_contents(getAttemptFile(), json_encode($attempts));
}

function resetAttempts() {
    $file = getAttemptFile();
    if (file_exists($file)) unlink($file);
}

function isLockedOut() {
    global $MAX_ATTEMPTS, $LOCKOUT_MINUTES;
    $attempts = getAttempts();
    if ($attempts['count'] >= $MAX_ATTEMPTS) {
        if (time() - $attempts['last'] < $LOCKOUT_MINUTES * 60) {
            return true;
        }
        resetAttempts();
    }
    return false;
}

function formatBytes($bytes) {
    if ($bytes >= 1048576) return round($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024, 2) . ' KB';
    return $bytes . ' B';
}

// ===== AUTHENTICATION =====
$authenticated = isset($_SESSION['update_authenticated']) && $_SESSION['update_authenticated'] === true;
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password']) && !$authenticated) {
    if (isLockedOut()) {
        $error = 'Too many failed attempts. Try again later.';
        writeLog('LOGIN BLOCKED - locked out');
    } elseif ($UPDATE_PASSWORD_HASH === '$2y$10$VLEUpdateScriptDefaultHashDoNotUseInProduction000') {
        $error = 'Update password not configured. Generate a hash first (see instructions).';
    } elseif (password_verify($_POST['password'], $UPDATE_PASSWORD_HASH)) {
        $_SESSION['update_authenticated'] = true;
        $authenticated = true;
        resetAttempts();
        writeLog('LOGIN SUCCESS');
    } else {
        recordAttempt();
        $remaining = $MAX_ATTEMPTS - getAttempts()['count'];
        $error = "Invalid password. $remaining attempts remaining.";
        writeLog('LOGIN FAILED');
    }
}

// Logout
if (isset($_GET['logout'])) {
    unset($_SESSION['update_authenticated']);
    header('Location: server_update.php');
    exit;
}

// ===== APPLY UPDATE =====
$updateResult = null;

if ($authenticated && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['apply_update'])) {
    $zipFile = $_POST['zip_file'] ?? '';
    
    // Validate: only allow zip files in the root directory
    $zipFile = basename($zipFile); // Prevent directory traversal
    $zipPath = __DIR__ . '/' . $zipFile;
    
    if (!preg_match('/^update_package_\d{8}_\d{6}\.zip$/', $zipFile)) {
        $error = 'Invalid package filename format.';
        writeLog("UPDATE REJECTED - invalid filename: $zipFile");
    } elseif (!file_exists($zipPath)) {
        $error = "Package not found: $zipFile";
        writeLog("UPDATE REJECTED - file not found: $zipFile");
    } else {
        writeLog("UPDATE STARTED - $zipFile");
        
        $updateResult = [
            'extracted' => [],
            'deleted' => [],
            'backed_up' => [],
            'errors' => [],
            'manifest' => null,
        ];

        $zip = new ZipArchive();
        $result = $zip->open($zipPath);
        
        if ($result !== true) {
            $error = "Could not open zip file. Error: $result";
            writeLog("UPDATE FAILED - cannot open zip: $result");
        } else {
            // Read manifest
            $manifestJson = $zip->getFromName('_update_manifest.json');
            if ($manifestJson) {
                $updateResult['manifest'] = json_decode($manifestJson, true);
            }
            
            // Create backup directory
            $backupDir = __DIR__ . '/backups/update_backup_' . date('Ymd_His');
            if (!is_dir($backupDir)) {
                mkdir($backupDir, 0755, true);
            }
            
            // Protected paths - never overwrite these
            $serverProtected = [
                'includes/config.production.php',
                'server_update.php',
                'package_update.php',
                '_update_manifest.json',
            ];
            $protectedDirs = ['uploads/', 'backups/', 'config/', 'logs/', '.git/'];
            
            // Extract files
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = $zip->getNameIndex($i);
                
                // Skip manifest
                if ($entry === '_update_manifest.json') continue;
                
                // Skip protected files
                $isProtected = false;
                if (in_array($entry, $serverProtected)) {
                    $isProtected = true;
                }
                foreach ($protectedDirs as $pd) {
                    if (strpos($entry, $pd) === 0) {
                        $isProtected = true;
                        break;
                    }
                }
                if ($isProtected) {
                    $updateResult['errors'][] = "SKIPPED (protected): $entry";
                    continue;
                }
                
                $targetPath = __DIR__ . '/' . $entry;
                
                // Backup existing file
                if (file_exists($targetPath)) {
                    $backupPath = $backupDir . '/' . $entry;
                    $backupSubdir = dirname($backupPath);
                    if (!is_dir($backupSubdir)) {
                        mkdir($backupSubdir, 0755, true);
                    }
                    if (copy($targetPath, $backupPath)) {
                        $updateResult['backed_up'][] = $entry;
                    }
                }
                
                // Ensure target directory exists
                $targetDir = dirname($targetPath);
                if (!is_dir($targetDir)) {
                    mkdir($targetDir, 0755, true);
                }
                
                // Extract file
                $content = $zip->getFromName($entry);
                if ($content !== false) {
                    if (file_put_contents($targetPath, $content) !== false) {
                        $updateResult['extracted'][] = $entry;
                    } else {
                        $updateResult['errors'][] = "WRITE FAILED: $entry";
                    }
                } else {
                    $updateResult['errors'][] = "READ FAILED: $entry";
                }
            }
            
            $zip->close();
            
            // Handle deletions from manifest
            if ($updateResult['manifest'] && !empty($updateResult['manifest']['deleted'])) {
                foreach ($updateResult['manifest']['deleted'] as $delFile) {
                    // Security: only delete files, validate path
                    $delFile = str_replace(['..', "\0"], '', $delFile);
                    $delPath = __DIR__ . '/' . $delFile;
                    
                    // Never delete protected paths
                    $isDeletionProtected = false;
                    foreach ($protectedDirs as $pd) {
                        if (strpos($delFile, $pd) === 0) {
                            $isDeletionProtected = true;
                            break;
                        }
                    }
                    
                    if (!$isDeletionProtected && file_exists($delPath) && is_file($delPath)) {
                        // Backup before delete
                        $backupPath = $backupDir . '/' . $delFile;
                        $bDir = dirname($backupPath);
                        if (!is_dir($bDir)) mkdir($bDir, 0755, true);
                        copy($delPath, $backupPath);
                        
                        if (unlink($delPath)) {
                            $updateResult['deleted'][] = $delFile;
                        } else {
                            $updateResult['errors'][] = "DELETE FAILED: $delFile";
                        }
                    }
                }
            }
            
            $extractedCount = count($updateResult['extracted']);
            $deletedCount = count($updateResult['deleted']);
            $errorCount = count($updateResult['errors']);
            
            writeLog("UPDATE COMPLETE - Extracted: $extractedCount, Deleted: $deletedCount, Errors: $errorCount");
            $success = "Update applied successfully! $extractedCount files updated, $deletedCount files removed.";
            
            // Optionally rename the zip so it's not reapplied
            $appliedName = str_replace('.zip', '_applied.zip', $zipPath);
            rename($zipPath, $appliedName);
        }
    }
}

// ===== FIND AVAILABLE UPDATE PACKAGES =====
$availablePackages = [];
if ($authenticated) {
    $files = glob(__DIR__ . '/update_package_*.zip');
    if ($files) {
        foreach ($files as $f) {
            $availablePackages[] = [
                'name' => basename($f),
                'size' => formatBytes(filesize($f)),
                'date' => date('Y-m-d H:i:s', filemtime($f)),
            ];
        }
        // Sort by date descending
        usort($availablePackages, function($a, $b) {
            return strcmp($b['date'], $a['date']);
        });
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VLE Server Update</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Segoe UI', system-ui, sans-serif; background: #f0f2f5; min-height: 100vh; display: flex; align-items: center; justify-content: center; }
        .container { width: 100%; max-width: 700px; padding: 20px; }
        .card { background: #fff; border-radius: 12px; padding: 32px; box-shadow: 0 4px 20px rgba(0,0,0,.08); margin-bottom: 20px; }
        h1 { color: #1a237e; font-size: 24px; margin-bottom: 8px; }
        h2 { color: #333; font-size: 18px; margin-bottom: 16px; }
        .subtitle { color: #666; font-size: 14px; margin-bottom: 24px; }
        input[type="password"] { width: 100%; padding: 12px 16px; border: 2px solid #e0e0e0; border-radius: 8px; font-size: 15px; transition: border-color .2s; }
        input[type="password"]:focus { outline: none; border-color: #1a237e; }
        .btn { display: inline-block; padding: 12px 32px; background: #1a237e; color: #fff; border: none; border-radius: 8px; font-size: 15px; cursor: pointer; transition: background .2s; }
        .btn:hover { background: #283593; }
        .btn-danger { background: #c62828; }
        .btn-danger:hover { background: #b71c1c; }
        .btn-sm { padding: 8px 20px; font-size: 13px; }
        .alert { padding: 14px 18px; border-radius: 8px; margin-bottom: 16px; font-size: 14px; }
        .alert-error { background: #ffebee; color: #c62828; border: 1px solid #ffcdd2; }
        .alert-success { background: #e8f5e9; color: #2e7d32; border: 1px solid #c8e6c9; }
        .alert-info { background: #e3f2fd; color: #1565c0; border: 1px solid #bbdefb; }
        .alert-warning { background: #fff8e1; color: #f57f17; border: 1px solid #fff9c4; }
        table { width: 100%; border-collapse: collapse; margin: 12px 0; }
        th, td { padding: 10px 12px; text-align: left; border-bottom: 1px solid #f0f0f0; font-size: 13px; }
        th { background: #fafafa; font-weight: 600; color: #555; }
        .badge { display: inline-block; padding: 2px 10px; border-radius: 10px; font-size: 11px; font-weight: 600; }
        .badge-ok { background: #c8e6c9; color: #2e7d32; }
        .badge-err { background: #ffcdd2; color: #c62828; }
        .badge-warn { background: #fff3e0; color: #e65100; }
        pre { background: #263238; color: #aed581; padding: 14px; border-radius: 8px; font-size: 12px; max-height: 300px; overflow: auto; margin-top: 12px; }
        .logout { float: right; color: #999; text-decoration: none; font-size: 13px; }
        .logout:hover { color: #c62828; }
        .result-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin: 16px 0; }
        .stat { text-align: center; padding: 16px; border-radius: 8px; background: #f5f5f5; }
        .stat-num { font-size: 28px; font-weight: 700; color: #1a237e; }
        .stat-label { font-size: 12px; color: #666; margin-top: 4px; }
        .form-group { margin-bottom: 16px; }
        label { display: block; font-weight: 600; margin-bottom: 6px; color: #333; font-size: 14px; }
        select { width: 100%; padding: 10px 12px; border: 2px solid #e0e0e0; border-radius: 8px; font-size: 14px; }
        .instructions { background: #f8f9fa; border-radius: 8px; padding: 16px; margin-top: 16px; }
        .instructions h3 { font-size: 14px; color: #1a237e; margin-bottom: 8px; }
        .instructions ol { padding-left: 20px; font-size: 13px; color: #555; }
        .instructions li { margin-bottom: 6px; }
    </style>
</head>
<body>
<div class="container">

<?php if (!$authenticated): ?>
    <!-- LOGIN FORM -->
    <div class="card">
        <h1>🔒 VLE Server Update</h1>
        <p class="subtitle">Enter the update password to manage server updates.</p>
        
        <?php if ($error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        
        <?php if (isLockedOut()): ?>
            <div class="alert alert-error">Account locked due to too many failed attempts. Try again in <?= $LOCKOUT_MINUTES ?> minutes.</div>
        <?php else: ?>
            <form method="POST">
                <div class="form-group">
                    <label for="password">Update Password</label>
                    <input type="password" id="password" name="password" placeholder="Enter update password" required autofocus>
                </div>
                <button type="submit" class="btn">Authenticate</button>
            </form>
        <?php endif; ?>
        
        <div class="instructions">
            <h3>First Time Setup</h3>
            <ol>
                <li>On <strong>localhost</strong>, visit: <code>server_update.php?generate_hash=YOUR_PASSWORD</code></li>
                <li>Copy the generated hash</li>
                <li>Replace the <code>$UPDATE_PASSWORD_HASH</code> value in this file on the server</li>
                <li>Upload the file and log in with your chosen password</li>
            </ol>
        </div>
    </div>

<?php else: ?>
    <!-- AUTHENTICATED - UPDATE DASHBOARD -->
    <div class="card">
        <a href="?logout=1" class="logout">🚪 Logout</a>
        <h1>🔄 VLE Server Update</h1>
        <p class="subtitle">Apply update packages to the server safely.</p>
        
        <?php if ($error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>
        
        <?php if ($updateResult): ?>
            <h2>Update Results</h2>
            <div class="result-grid">
                <div class="stat">
                    <div class="stat-num"><?= count($updateResult['extracted']) ?></div>
                    <div class="stat-label">Files Updated</div>
                </div>
                <div class="stat">
                    <div class="stat-num"><?= count($updateResult['deleted']) ?></div>
                    <div class="stat-label">Files Removed</div>
                </div>
                <div class="stat">
                    <div class="stat-num"><?= count($updateResult['errors']) ?></div>
                    <div class="stat-label">Errors</div>
                </div>
            </div>
            
            <?php if ($updateResult['manifest']): ?>
                <div class="alert alert-info">
                    Package from: <?= htmlspecialchars($updateResult['manifest']['created'] ?? 'unknown') ?> | 
                    Branch: <?= htmlspecialchars($updateResult['manifest']['git_branch'] ?? 'unknown') ?>
                </div>
            <?php endif; ?>
            
            <?php if ($updateResult['backed_up']): ?>
                <details>
                    <summary style="cursor:pointer;font-weight:600;margin:8px 0">📋 Backed up files (<?= count($updateResult['backed_up']) ?>)</summary>
                    <pre><?php foreach ($updateResult['backed_up'] as $f) echo htmlspecialchars($f) . "\n"; ?></pre>
                </details>
            <?php endif; ?>
            
            <?php if ($updateResult['extracted']): ?>
                <details>
                    <summary style="cursor:pointer;font-weight:600;margin:8px 0">✅ Updated files (<?= count($updateResult['extracted']) ?>)</summary>
                    <pre><?php foreach ($updateResult['extracted'] as $f) echo htmlspecialchars($f) . "\n"; ?></pre>
                </details>
            <?php endif; ?>
            
            <?php if ($updateResult['errors']): ?>
                <details open>
                    <summary style="cursor:pointer;font-weight:600;margin:8px 0;color:#c62828">⚠️ Errors (<?= count($updateResult['errors']) ?>)</summary>
                    <pre><?php foreach ($updateResult['errors'] as $f) echo htmlspecialchars($f) . "\n"; ?></pre>
                </details>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    
    <!-- APPLY UPDATE FORM -->
    <div class="card">
        <h2>Apply Update Package</h2>
        
        <?php if (empty($availablePackages)): ?>
            <div class="alert alert-warning">
                No update packages found. Upload an <code>update_package_*.zip</code> file to the server root directory first.
            </div>
        <?php else: ?>
            <form method="POST" onsubmit="return confirm('This will overwrite existing files with the package contents. Existing files will be backed up. Continue?');">
                <div class="form-group">
                    <label>Select Update Package</label>
                    <select name="zip_file" required>
                        <option value="">-- Select a package --</option>
                        <?php foreach ($availablePackages as $pkg): ?>
                            <option value="<?= htmlspecialchars($pkg['name']) ?>">
                                <?= htmlspecialchars($pkg['name']) ?> (<?= $pkg['size'] ?> — <?= $pkg['date'] ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" name="apply_update" value="1" class="btn">🚀 Apply Update</button>
            </form>
        <?php endif; ?>
    </div>
    
    <!-- INSTRUCTIONS -->
    <div class="card">
        <h2>How to Update</h2>
        <div class="instructions">
            <ol>
                <li>On your <strong>local machine</strong>, run <code>package_update.php</code> to create a zip of all changed files</li>
                <li>Download the <code>update_package_*.zip</code> from your local project folder</li>
                <li>Upload the zip to the <strong>server root</strong> (<code>htdocs/</code>) using the InfinityFree File Manager</li>
                <li>Come back here and select the package to apply</li>
                <li>Files are backed up automatically in <code>backups/update_backup_*/</code> before overwriting</li>
            </ol>
        </div>
        <div class="instructions" style="margin-top:12px;background:#fff3e0">
            <h3>⚠️ Safety Notes</h3>
            <ol>
                <li>Protected files (<code>config.production.php</code>, <code>uploads/</code>, <code>backups/</code>) are never overwritten</li>
                <li>Every replaced file is backed up before changes</li>
                <li>The update log is saved in <code>logs/update_log.txt</code></li>
                <li>Used packages are renamed with <code>_applied</code> suffix to prevent reapplication</li>
            </ol>
        </div>
    </div>

<?php endif; ?>

</div>
</body>
</html>
