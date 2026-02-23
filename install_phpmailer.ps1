# Download and install PHPMailer
Write-Host "Downloading PHPMailer..." -ForegroundColor Yellow

$files = @{
    "PHPMailer.php" = "https://raw.githubusercontent.com/PHPMailer/PHPMailer/v6.9.1/src/PHPMailer.php"
    "SMTP.php" = "https://raw.githubusercontent.com/PHPMailer/PHPMailer/v6.9.1/src/SMTP.php"
    "Exception.php" = "https://raw.githubusercontent.com/PHPMailer/PHPMailer/v6.9.1/src/Exception.php"
    "OAuth.php" = "https://raw.githubusercontent.com/PHPMailer/PHPMailer/v6.9.1/src/OAuth.php"
    "POP3.php" = "https://raw.githubusercontent.com/PHPMailer/PHPMailer/v6.9.1/src/POP3.php"
}

$destPath = "vendor\phpmailer\phpmailer\src"

foreach ($file in $files.Keys) {
    try {
        $url = $files[$file]
        $dest = Join-Path $destPath $file
        Write-Host "Downloading $file..." -ForegroundColor Cyan
        Invoke-WebRequest -Uri $url -OutFile $dest -UseBasicParsing
        Write-Host "  ✓ Downloaded $file" -ForegroundColor Green
    } catch {
        Write-Host "  ✗ Failed to download $file : $_" -ForegroundColor Red
    }
}

Write-Host "`nChecking downloaded files..." -ForegroundColor Yellow
Get-ChildItem -Path $destPath | ForEach-Object {
    Write-Host "  ✓ $($_.Name) - $([math]::Round($_.Length / 1KB, 2)) KB" -ForegroundColor Green
}

Write-Host "`nPHPMailer installation complete!" -ForegroundColor Green
