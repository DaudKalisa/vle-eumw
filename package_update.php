<?php
/**
 * VLE Update Packager — Run locally to create a deployment zip
 * 
 * Usage: 
 *   Browser: http://localhost/vle-eumw/package_update.php
 *   CLI:     php package_update.php
 * 
 * Creates an update_package_YYYYMMDD_HHMMSS.zip containing only
 * modified/new files (detected via git) ready to upload to production.
 */

// Only allow on localhost
if (php_sapi_name() !== 'cli') {
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if (strpos($host, 'localhost') === false && strpos($host, '127.0.0.1') === false) {
        http_response_code(403);
        die('This script can only run on localhost.');
    }
}

$isCli = php_sapi_name() === 'cli';

if (!$isCli) {
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>VLE Update Packager</title>';
    echo '<style>
        body { font-family: "Segoe UI", sans-serif; max-width: 900px; margin: 40px auto; padding: 20px; background: #f5f5f5; }
        .card { background: #fff; border-radius: 8px; padding: 24px; margin-bottom: 20px; box-shadow: 0 2px 8px rgba(0,0,0,.08); }
        h1 { color: #1a237e; margin-top: 0; }
        h2 { color: #333; border-bottom: 2px solid #e0e0e0; padding-bottom: 8px; }
        pre { background: #263238; color: #aed581; padding: 16px; border-radius: 6px; overflow-x: auto; font-size: 13px; max-height: 400px; overflow-y: auto; }
        .badge { display: inline-block; padding: 3px 10px; border-radius: 12px; font-size: 12px; font-weight: 600; }
        .badge-new { background: #c8e6c9; color: #2e7d32; }
        .badge-mod { background: #fff3e0; color: #e65100; }
        .badge-del { background: #ffcdd2; color: #c62828; }
        .success { background: #e8f5e9; border-left: 4px solid #4caf50; padding: 16px; border-radius: 4px; }
        .error { background: #ffebee; border-left: 4px solid #f44336; padding: 16px; border-radius: 4px; }
        .warning { background: #fff8e1; border-left: 4px solid #ffc107; padding: 16px; border-radius: 4px; }
        .btn { display: inline-block; padding: 10px 28px; background: #1a237e; color: #fff; border: none; border-radius: 6px; font-size: 15px; cursor: pointer; text-decoration: none; }
        .btn:hover { background: #283593; }
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; padding: 8px 12px; border-bottom: 1px solid #eee; font-size: 13px; }
        th { background: #f5f5f5; font-weight: 600; }
        .count { font-size: 28px; font-weight: 700; color: #1a237e; }
    </style>';
    echo '</head><body>';
    echo '<h1>📦 VLE Update Packager</h1>';
}

// Protected directories/files that should NEVER be included in updates
$protected = [
    'uploads/',           // User uploaded files
    'backups/',           // Database backups
    'config/',            // Server-specific config
    'logs/',              // Log files
    '.git/',              // Git internals
    'node_modules/',      // Dependencies
    'package_update.php', // This script
    'server_update.php',  // Server update script
    '.env',               // Environment variables
    'includes/config.production.php', // Production config
];

// Files that should be excluded (debug/setup scripts not needed on production)
$excludePatterns = [
    '/^check_/',          // Debug check scripts
    '/^drop_/',           // Dangerous drop scripts
    '/^reset_/',          // Reset scripts
    '/^fix_/',            // One-time fix scripts  
    '/^create_dean_user/',
    '/^create_research_coordinator/',
    '/^setup_sample_/',   // Sample data scripts
    '/^setup_sample_content/',
    '/^setup_sample_users/',
    '/^composer-setup\.php$/',
    '/\.bak$/',           // Backup files
    '/^_guideline/',      // Temp files
    '/^dissertation_guidelines_raw/',
];

$baseDir = __DIR__;

// Get git status
$gitStatus = shell_exec('cd ' . escapeshellarg($baseDir) . ' && git status --porcelain 2>&1');

if ($gitStatus === null || strpos($gitStatus, 'fatal') !== false) {
    $msg = "Error: Could not get git status. Make sure git is installed and this is a git repository.";
    if ($isCli) { echo $msg . "\n"; } else { echo "<div class='error'>$msg</div></body></html>"; }
    exit(1);
}

$lines = array_filter(explode("\n", trim($gitStatus)));
$modified = [];
$newFiles = [];
$deleted = [];
$skippedProtected = [];
$skippedPattern = [];

foreach ($lines as $rawLine) {
    if (strlen($rawLine) < 4) continue;
    
    // git status --porcelain format: XY filename (first 2 chars = status, char 3 = space)
    $status = substr($rawLine, 0, 2);
    $file = trim(substr($rawLine, 3));
    
    // Remove quotes from filenames with spaces
    $file = trim($file, '"');
    if (empty($file)) continue;
    
    // Check if file is in a protected path
    $isProtected = false;
    foreach ($protected as $prot) {
        if (strpos($file, $prot) === 0 || $file === rtrim($prot, '/')) {
            $isProtected = true;
            $skippedProtected[] = $file;
            break;
        }
    }
    if ($isProtected) continue;
    
    // Check exclude patterns
    $basename = basename($file);
    $isExcluded = false;
    foreach ($excludePatterns as $pattern) {
        if (preg_match($pattern, $basename)) {
            $isExcluded = true;
            $skippedPattern[] = $file;
            break;
        }
    }
    if ($isExcluded) continue;
    
    // Categorize — check both staged (index) and working tree positions
    $indexStatus = $status[0];
    $workTreeStatus = $status[1];
    
    if ($indexStatus === 'D' || $workTreeStatus === 'D') {
        $deleted[] = $file;
    } elseif ($status === '??' || $indexStatus === 'A') {
        // Only include new files that exist and are code files
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $codeExts = ['php', 'html', 'htm', 'css', 'js', 'json', 'sql', 'htaccess', 'ini', 'md', 'txt', 'xml'];
        if (in_array($ext, $codeExts) || $basename === '.htaccess' || $basename === '.user.ini') {
            $newFiles[] = $file;
        }
    } else {
        // Modified files (staged M or unstaged M)
        $modified[] = $file;
    }
}

$totalFiles = count($modified) + count($newFiles);

if (!$isCli) {
    echo '<div class="card">';
    echo '<h2>Change Summary</h2>';
    echo '<table>';
    echo '<tr><td><span class="badge badge-mod">MODIFIED</span></td><td><span class="count">' . count($modified) . '</span> files</td></tr>';
    echo '<tr><td><span class="badge badge-new">NEW</span></td><td><span class="count">' . count($newFiles) . '</span> files</td></tr>';
    echo '<tr><td><span class="badge badge-del">DELETED</span></td><td><span class="count">' . count($deleted) . '</span> files</td></tr>';
    echo '<tr><td>Skipped (protected)</td><td>' . count($skippedProtected) . ' files</td></tr>';
    echo '<tr><td>Skipped (debug/setup)</td><td>' . count($skippedPattern) . ' files</td></tr>';
    echo '</table>';
    echo '</div>';
}

if ($totalFiles === 0) {
    $msg = "No files to package. All changes are either protected or excluded.";
    if ($isCli) { echo $msg . "\n"; } else { echo "<div class='warning'>$msg</div></body></html>"; }
    exit(0);
}

// Show file lists
if (!$isCli) {
    if ($modified) {
        echo '<div class="card"><h2>Modified Files (' . count($modified) . ')</h2><pre>';
        foreach ($modified as $f) echo htmlspecialchars($f) . "\n";
        echo '</pre></div>';
    }
    if ($newFiles) {
        echo '<div class="card"><h2>New Files (' . count($newFiles) . ')</h2><pre>';
        foreach ($newFiles as $f) echo htmlspecialchars($f) . "\n";
        echo '</pre></div>';
    }
    if ($deleted) {
        echo '<div class="card"><h2>Deleted on Server (' . count($deleted) . ')</h2><pre>';
        foreach ($deleted as $f) echo htmlspecialchars($f) . "\n";
        echo '</pre></div>';
    }
} else {
    echo "Modified: " . count($modified) . " | New: " . count($newFiles) . " | Deleted: " . count($deleted) . "\n";
}

// Build the zip if requested or always in CLI mode
$doPackage = $isCli || isset($_GET['package']);

if (!$doPackage && !$isCli) {
    echo '<div class="card">';
    echo '<a class="btn" href="?package=1">📦 Create Update Package</a>';
    echo '</div>';
    echo '</body></html>';
    exit(0);
}

// Create the zip
$timestamp = date('Ymd_His');
$zipName = "update_package_{$timestamp}.zip";
$zipPath = $baseDir . DIRECTORY_SEPARATOR . $zipName;

$zip = new ZipArchive();
$result = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

if ($result !== true) {
    $msg = "Error: Could not create zip file. Error code: $result";
    if ($isCli) { echo $msg . "\n"; } else { echo "<div class='error'>$msg</div></body></html>"; }
    exit(1);
}

$addedCount = 0;
$failedFiles = [];

// Add modified and new files
foreach (array_merge($modified, $newFiles) as $file) {
    $fullPath = $baseDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $file);
    if (file_exists($fullPath) && is_file($fullPath)) {
        $zip->addFile($fullPath, $file);
        $addedCount++;
    } else {
        $failedFiles[] = $file;
    }
}

// Add a manifest file with metadata
$manifest = [
    'created' => date('Y-m-d H:i:s'),
    'total_files' => $addedCount,
    'modified' => $modified,
    'new_files' => $newFiles,
    'deleted' => $deleted,
    'git_branch' => trim(shell_exec('cd ' . escapeshellarg($baseDir) . ' && git branch --show-current 2>&1')),
    'git_commit' => trim(shell_exec('cd ' . escapeshellarg($baseDir) . ' && git log -1 --format="%H %s" 2>&1')),
];
$zip->addFromString('_update_manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

$zip->close();

$zipSize = filesize($zipPath);
$zipSizeMB = round($zipSize / 1024 / 1024, 2);

if ($isCli) {
    echo "\n✅ Update package created: $zipName ($zipSizeMB MB)\n";
    echo "   Files included: $addedCount\n";
    echo "   Files to delete on server: " . count($deleted) . "\n";
    if ($failedFiles) {
        echo "   ⚠ Failed to include: " . count($failedFiles) . " files\n";
    }
    echo "\nNext steps:\n";
    echo "  1. Upload '$zipName' to your server root via File Manager\n";
    echo "  2. Visit https://your-domain/server_update.php in your browser\n";
    echo "  3. Enter the update password and select the uploaded zip\n";
} else {
    echo '<div class="card">';
    echo '<div class="success">';
    echo '<h2 style="margin-top:0">✅ Update Package Created</h2>';
    echo '<table>';
    echo "<tr><td><strong>File:</strong></td><td><a href='$zipName' download>$zipName</a></td></tr>";
    echo "<tr><td><strong>Size:</strong></td><td>{$zipSizeMB} MB</td></tr>";
    echo "<tr><td><strong>Files included:</strong></td><td>$addedCount</td></tr>";
    echo "<tr><td><strong>Files to delete:</strong></td><td>" . count($deleted) . "</td></tr>";
    echo '</table>';
    echo '</div>';
    
    if ($failedFiles) {
        echo '<div class="warning" style="margin-top:16px"><strong>⚠ Could not include:</strong><br>';
        foreach ($failedFiles as $f) echo htmlspecialchars($f) . '<br>';
        echo '</div>';
    }
    
    echo '<h2 style="margin-top:24px">Next Steps</h2>';
    echo '<ol>';
    echo "<li>Download <strong>$zipName</strong> from your project folder</li>";
    echo '<li>Upload it to your server root via <strong>InfinityFree File Manager</strong></li>';
    echo '<li>Visit <code>https://your-domain/server_update.php</code> in your browser</li>';
    echo '<li>Enter the update password and apply the update</li>';
    echo '</ol>';
    echo '</div>';
    echo '</body></html>';
}
