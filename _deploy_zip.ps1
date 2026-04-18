# Deploy VLE to production via FTP
$ErrorActionPreference = "Stop"

$ftpHost = "194.164.74.45"
$ftpUser = "u615976264"
$ftpPass = "Kalisa3283!"
$remotePath = "/vle"

$projectDir = "C:\xampp\htdocs\vle-eumw"
$zipFile = "$env:TEMP\vle_deploy.zip"

Write-Host "=== VLE Production Deployment ===" -ForegroundColor Cyan

# 1. Create zip (exclude unnecessary files)
Write-Host "`n[1/4] Creating deployment archive..." -ForegroundColor Yellow
if (Test-Path $zipFile) { Remove-Item $zipFile -Force }

$exclude = @("*.git*", "*.md", "_deploy_zip.ps1", "_check_*", "_test_*", "_tmp_*", "*.log")
Compress-Archive -Path "$projectDir\*" -DestinationPath $zipFile -Force
Write-Host "  Archive created: $zipFile ($('{0:N1}' -f ((Get-Item $zipFile).Length / 1MB)) MB)"

# 2. Upload extract helper
Write-Host "`n[2/4] Uploading extraction script..." -ForegroundColor Yellow
$extractPhp = @"
<?php
`$zip = new ZipArchive;
if (`$zip->open('vle_deploy.zip') === TRUE) {
    `$zip->extractTo('.');
    `$zip->close();
    unlink('vle_deploy.zip');
    unlink(__FILE__);
    echo 'OK: Extracted and cleaned up.';
} else {
    echo 'ERROR: Could not open zip.';
}
"@
$extractFile = "$env:TEMP\_extract_deploy.php"
Set-Content -Path $extractFile -Value $extractPhp -Encoding UTF8

# Upload extract script
curl.exe -s -T $extractFile "ftp://${ftpUser}:${ftpPass}@${ftpHost}${remotePath}/_extract_deploy.php" --ftp-create-dirs 2>&1 | Out-Null
Write-Host "  Extraction script uploaded."

# 3. Upload zip
Write-Host "`n[3/4] Uploading archive (this may take a moment)..." -ForegroundColor Yellow
curl.exe -T $zipFile "ftp://${ftpUser}:${ftpPass}@${ftpHost}${remotePath}/vle_deploy.zip" --ftp-create-dirs 2>&1
Write-Host "  Archive uploaded."

# 4. Trigger extraction
Write-Host "`n[4/4] Extracting on server..." -ForegroundColor Yellow
$response = Invoke-WebRequest -Uri "https://vle.exploitsonline.com/_extract_deploy.php" -UseBasicParsing -TimeoutSec 120
Write-Host "  Server response: $($response.Content)" -ForegroundColor Green

# Cleanup
Remove-Item $zipFile -Force -ErrorAction SilentlyContinue
Remove-Item $extractFile -Force -ErrorAction SilentlyContinue

Write-Host "`n=== Deployment Complete ===" -ForegroundColor Cyan
Write-Host "Site: https://vle.exploitsonline.com" -ForegroundColor Green
