# Fix PHPMailer Missing Error

## Problem
The error occurs because the PHPMailer library files are missing from the `vendor` folder.

## Solution

### Option 1: Run the Batch File (Easiest)
1. Double-click `download_phpmailer.bat` in the vle_system folder
2. Wait for the download to complete
3. Refresh your browser and try again

### Option 2: Manual Download
Download these files and place them in `C:\xampp\htdocs\vle_system\vendor\phpmailer\phpmailer\src\`:

1. **PHPMailer.php**
   - URL: https://raw.githubusercontent.com/PHPMailer/PHPMailer/v6.9.1/src/PHPMailer.php
   
2. **SMTP.php**
   - URL: https://raw.githubusercontent.com/PHPMailer/PHPMailer/v6.9.1/src/SMTP.php
   
3. **Exception.php**
   - URL: https://raw.githubusercontent.com/PHPMailer/PHPMailer/v6.9.1/src/Exception.php

Also download this file to `C:\xampp\htdocs\vle_system\vendor\composer\`:

4. **ClassLoader.php**
   - URL: https://raw.githubusercontent.com/composer/composer/main/src/Composer/Autoload/ClassLoader.php

### Option 3: Use PowerShell
1. Open PowerShell as Administrator
2. Navigate to the VLE folder:
   ```powershell
   cd C:\xampp\htdocs\vle_system
   ```
3. Run:
   ```powershell
   .\download_phpmailer.bat
   ```

### Verify Installation
After downloading, you should have these files:
- `vendor/phpmailer/phpmailer/src/PHPMailer.php`
- `vendor/phpmailer/phpmailer/src/SMTP.php`
- `vendor/phpmailer/phpmailer/src/Exception.php`
- `vendor/composer/ClassLoader.php`

The email functionality should now work!

## Alternative: Disable Email Functionality
If you don't need email features right now, you can temporarily disable them by commenting out the email function calls in the announcement code.
