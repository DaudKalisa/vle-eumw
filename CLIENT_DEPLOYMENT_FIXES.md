# VLE System - Error 500 Resolution Guide

## Issue Resolved: Internal Server Error (Error 500)

**Date:** January 12, 2026  
**Status:** ✅ RESOLVED

## Root Cause

The Internal Server Error was caused by invalid Apache directives in the `.htaccess` file:
- **`<Directory>` blocks** were present in `.htaccess`, which are NOT allowed
- `<Directory>` directives can only be used in Apache's main configuration files (httpd.conf)
- This caused Apache to return error 500 for all requests

## Fixes Applied

### 1. ✅ Removed Invalid `.htaccess` Directives
- Removed all `<Directory>` blocks from `.htaccess`
- Replaced with proper `.htaccess`-compatible directives
- File now uses only allowed directives

### 2. ✅ Apache Restarted
- Stopped and restarted Apache/httpd processes
- New configuration loaded successfully
- Error logs now clean (no more "Directory not allowed" errors)

### 3. ✅ Verified Website Functionality
- Test shows HTTP 200 (Success) response
- Website is now accessible
- No more Internal Server Error

## For Client Machine Deployment

If you encounter Error 500 on client machines, follow these steps:

### Step 1: Verify `.htaccess` File
Ensure the `.htaccess` file does NOT contain:
- `<Directory>` blocks
- `</Directory>` closing tags

These directives are forbidden in `.htaccess` files.

### Step 2: Check File Permissions
On client machines (especially Linux/shared hosting):
```bash
# Set proper permissions
chmod 644 .htaccess
chmod 755 includes/
chmod 644 includes/*.php
```

### Step 3: Enable PHP Error Logging
The system automatically creates error logs in:
- `logs/php_errors.log` - PHP errors
- Apache error log - Server errors

Check these logs if errors occur.

### Step 4: Verify Database Connection
Ensure `includes/config.production.php` exists with correct credentials:
```php
define('DB_HOST', 'your_db_host');
define('DB_USER', 'your_db_user');
define('DB_PASS', 'your_db_password');
define('DB_NAME', 'your_db_name');
define('SITE_URL', 'https://yourdomain.com');
define('APP_ENV', 'production');
```

### Step 5: Common Client Machine Issues

#### Issue: "Database connection error"
**Solution:** Verify database credentials in `config.production.php`

#### Issue: "Authentication file not found"
**Solution:** Ensure all files in `includes/` folder are uploaded

#### Issue: "Permission denied" errors
**Solution:** Check file/folder permissions (see Step 2)

#### Issue: Blank page with no errors
**Solution:** 
1. Enable error display temporarily:
   ```php
   ini_set('display_errors', 1);
   error_reporting(E_ALL);
   ```
2. Check Apache error logs
3. Check PHP error logs in `logs/` folder

## Testing on Client Machines

### Test 1: Basic Connectivity
```bash
curl -I http://yourdomain.com
# Should return: HTTP/1.1 200 OK
```

### Test 2: Database Connection
Create a test file `test_db.php`:
```php
<?php
require_once 'includes/config.php';
$conn = getDbConnection();
if ($conn) {
    echo "Database connection successful!";
} else {
    echo "Database connection failed!";
}
?>
```

### Test 3: PHP Configuration
Create `phpinfo.php`:
```php
<?php phpinfo(); ?>
```
Access it at: `http://yourdomain.com/phpinfo.php`
**⚠️ DELETE THIS FILE AFTER TESTING!**

## .htaccess Configuration Summary

The current `.htaccess` file includes:
- ✅ URL Rewriting enabled
- ✅ Security headers (X-Frame-Options, X-XSS-Protection)
- ✅ File access restrictions (.sql, .log, .md files blocked)
- ✅ Compression enabled (mod_deflate)
- ✅ Browser caching enabled
- ✅ Upload/pictures directory protection
- ✅ PHP settings (if allowed by host)

## Troubleshooting Commands

### Check Apache Error Log (on server)
```bash
tail -f /var/log/apache2/error.log
# or
tail -f /usr/local/apache/logs/error.log
```

### Check PHP Error Log
```bash
tail -f logs/php_errors.log
```

### Restart Apache
```bash
# Linux
sudo service apache2 restart

# cPanel/Shared Hosting
# Use cPanel control panel to restart Apache
```

## Support

If issues persist:
1. Check the error logs (Apache + PHP)
2. Verify file permissions
3. Confirm database credentials
4. Test with simplified `.htaccess` (rename current to `.htaccess.bak` and create minimal version)

## Minimal .htaccess for Testing

If problems continue, test with this minimal `.htaccess`:
```apache
RewriteEngine On
DirectoryIndex index.php
Options -Indexes

<FilesMatch "\.(sql|log)$">
    Deny from all
</FilesMatch>
```

If this works, gradually add back features from the full `.htaccess`.

---
**Document Created:** January 12, 2026  
**Last Updated:** January 12, 2026  
**Status:** Active - Error 500 Resolved ✅
