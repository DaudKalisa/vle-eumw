# VLE System - Email Setup Installation Script
# Run this in PowerShell from the VLE system directory

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "VLE System - Email Setup Installation" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

# Step 1: Install PHPMailer
Write-Host "Step 1: Installing PHPMailer via Composer..." -ForegroundColor Yellow
if (Test-Path "composer.phar") {
    php composer.phar install
} elseif (Get-Command composer -ErrorAction SilentlyContinue) {
    composer install
} else {
    Write-Host "Composer not found! Please install Composer first:" -ForegroundColor Red
    Write-Host "Download from: https://getcomposer.org/download/" -ForegroundColor Red
    Write-Host ""
    Write-Host "Or download composer.phar to this directory" -ForegroundColor Red
    exit 1
}

Write-Host "PHPMailer installed successfully!" -ForegroundColor Green
Write-Host ""

# Step 2: Create announcements table
Write-Host "Step 2: Creating announcements table..." -ForegroundColor Yellow
php create_announcements_table.php

Write-Host ""
Write-Host "========================================" -ForegroundColor Cyan
Write-Host "Installation Complete!" -ForegroundColor Green
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""
Write-Host "Next Steps:" -ForegroundColor Yellow
Write-Host "1. Edit includes/email.php and configure your SMTP settings" -ForegroundColor White
Write-Host "2. Add email addresses to student and lecturer records in database" -ForegroundColor White
Write-Host "3. Test the email functionality" -ForegroundColor White
Write-Host ""
Write-Host "See EMAIL_SETUP.md for detailed configuration instructions" -ForegroundColor Cyan
Write-Host ""
