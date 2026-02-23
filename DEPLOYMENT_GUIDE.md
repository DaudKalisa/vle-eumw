# VLE System - Free Hosting Deployment Guide

## 📋 Pre-Deployment Checklist

Before deploying your VLE application online, ensure you have:

- ✅ All PHP files working locally
- ✅ Database exported (SQL file)
- ✅ All required files (PHP, CSS, JS, images)
- ✅ Environment configuration ready
- ✅ Database credentials for remote server

---

## 🌐 Free Hosting Options for PHP Applications

### Option 1: InfinityFree ⭐ (Recommended)
**Best for: Complete PHP + MySQL applications**

**Features:**
- ✅ Unlimited bandwidth
- ✅ 5GB disk space
- ✅ MySQL databases (400 connections)
- ✅ PHP 7.4 - 8.2
- ✅ Free subdomain (yourname.rf.gd, .wuaze.com, etc.)
- ✅ cPanel access
- ✅ No ads

**Limitations:**
- ⚠️ Daily hits limit (50,000)
- ⚠️ Can't send emails (SMTP needed)
- ⚠️ Some PHP functions disabled

**Sign Up:** https://infinityfree.net

---

### Option 2: 000webhost
**Best for: Quick deployment with good performance**

**Features:**
- ✅ 300MB disk space
- ✅ 3GB bandwidth
- ✅ 1 MySQL database
- ✅ PHP 7.4, 8.0
- ✅ Free SSL
- ✅ Website builder

**Limitations:**
- ⚠️ 1 hour daily downtime
- ⚠️ No email support
- ⚠️ Limited database size

**Sign Up:** https://www.000webhost.com

---

### Option 3: AwardSpace
**Best for: Reliable uptime**

**Features:**
- ✅ 1GB disk space
- ✅ 5GB bandwidth
- ✅ 1 MySQL database
- ✅ PHP support
- ✅ Free subdomain

**Limitations:**
- ⚠️ Limited control panel
- ⚠️ Slower performance

**Sign Up:** https://www.awardspace.com

---

### Option 4: FreeHosting.com
**Features:**
- ✅ 10GB disk space
- ✅ Unlimited bandwidth
- ✅ MySQL database
- ✅ PHP 7.x

**Limitations:**
- ⚠️ Ads on free plan
- ⚠️ Limited support

**Sign Up:** https://www.freehosting.com

---

## 🚀 Step-by-Step Deployment (InfinityFree Example)

### Step 1: Sign Up and Setup

1. **Create Account**
   - Go to https://infinityfree.net
   - Click "Sign Up Now"
   - Enter your email and create password
   - Verify your email

2. **Create Hosting Account**
   - Click "Create Account"
   - Choose a subdomain (e.g., `mvustan-vle.rf.gd`)
   - Or use custom domain (if you have one)
   - Select server location
   - Click "Create Account"

3. **Access Control Panel**
   - Wait 5-10 minutes for activation
   - Click "Control Panel" (cPanel/VistaPanel)
   - Login with provided credentials

---

### Step 2: Prepare Your Application

1. **Export Database**
   ```bash
   # Open phpMyAdmin on localhost
   # Select your database (e.g., vle_db)
   # Click "Export" tab
   # Choose "Quick" export method
   # Format: SQL
   # Click "Go"
   # Save the .sql file
   ```

2. **Create Configuration File**
   
   Create `config.production.php`:
   ```php
   <?php
   // Production Database Configuration
   define('DB_HOST', 'sqlXXX.infinityfree.net'); // Get from cPanel
   define('DB_USER', 'epizXXXX_username');      // Get from cPanel
   define('DB_PASS', 'your_password');          // Set in cPanel
   define('DB_NAME', 'epizXXXX_vle');          // Database name
   define('DB_CHARSET', 'utf8mb4');
   
   // Site URL
   define('SITE_URL', 'https://yoursite.rf.gd');
   
   // Error Reporting (OFF for production)
   error_reporting(0);
   ini_set('display_errors', 0);
   
   // Session Configuration
   ini_set('session.cookie_httponly', 1);
   ini_set('session.use_only_cookies', 1);
   ?>
   ```

3. **Update includes/config.php**
   
   Modify to auto-detect environment:
   ```php
   <?php
   // Auto-detect environment
   if ($_SERVER['HTTP_HOST'] === 'localhost' || $_SERVER['HTTP_HOST'] === '127.0.0.1') {
       // Local Development
       define('DB_HOST', 'localhost');
       define('DB_USER', 'root');
       define('DB_PASS', '');
       define('DB_NAME', 'vle_db');
       define('SITE_URL', 'http://localhost/vle_system');
   } else {
       // Production - Load production config
       require_once 'config.production.php';
   }
   
   define('DB_CHARSET', 'utf8mb4');
   
   // Database Connection Function
   function getDbConnection() {
       $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
       
       if ($conn->connect_error) {
           die("Database connection failed: " . $conn->connect_error);
       }
       
       $conn->set_charset(DB_CHARSET);
       return $conn;
   }
   
   // Start session if not already started
   if (session_status() === PHP_SESSION_NONE) {
       session_start();
   }
   ?>
   ```

4. **Create .htaccess File**
   
   Create `.htaccess` in root directory:
   ```apache
   # Enable RewriteEngine
   RewriteEngine On
   
   # Force HTTPS (if SSL available)
   RewriteCond %{HTTPS} off
   RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
   
   # Security Headers
   <IfModule mod_headers.c>
       Header set X-Content-Type-Options "nosniff"
       Header set X-Frame-Options "SAMEORIGIN"
       Header set X-XSS-Protection "1; mode=block"
   </IfModule>
   
   # Disable Directory Browsing
   Options -Indexes
   
   # Protect sensitive files
   <FilesMatch "^(config\.php|config\.production\.php|\.env)$">
       Order allow,deny
       Deny from all
   </FilesMatch>
   
   # Set default index
   DirectoryIndex index.php index.html
   
   # PHP Settings (if allowed)
   <IfModule mod_php7.c>
       php_value upload_max_filesize 10M
       php_value post_max_size 10M
       php_value max_execution_time 300
       php_value memory_limit 256M
   </IfModule>
   ```

5. **Compress Files**
   - Create a ZIP file of your entire vle_system folder
   - Or prepare for FTP upload

---

### Step 3: Upload Files

**Method A: File Manager (Recommended for beginners)**

1. Login to cPanel/VistaPanel
2. Open "File Manager"
3. Navigate to `htdocs` or `public_html` folder
4. Delete default files (index.html, etc.)
5. Click "Upload"
6. Upload your ZIP file
7. Right-click ZIP → "Extract"
8. Move all files from `vle_system` folder to root `htdocs`
9. Delete the ZIP and empty folder

**Method B: FTP Upload (Faster for large files)**

1. Get FTP credentials from cPanel:
   - **Host:** ftpupload.net or ftp.yoursite.rf.gd
   - **Username:** epizXXXX_username
   - **Password:** your_password
   - **Port:** 21

2. Download FileZilla Client (https://filezilla-project.org)

3. Connect to FTP:
   - File → Site Manager → New Site
   - Enter FTP credentials
   - Click "Connect"

4. Upload files:
   - Navigate to `/htdocs` on remote
   - Upload all VLE files to this folder

---

### Step 4: Setup Database

1. **Create Database**
   - In cPanel, find "MySQL Databases"
   - Click "Create Database"
   - Database name: `vle` (will become epizXXXX_vle)
   - Create password-protected user
   - Add user to database with ALL PRIVILEGES

2. **Import Database**
   - Open "phpMyAdmin" from cPanel
   - Select your database from left sidebar
   - Click "Import" tab
   - Choose your exported .sql file
   - Click "Go"
   - Wait for import to complete

3. **Note Credentials**
   - Database Host: `sqlXXX.infinityfree.net`
   - Database Name: `epizXXXX_vle`
   - Username: `epizXXXX_username`
   - Password: (your password)

4. **Update config.production.php**
   - Update with the actual credentials from step 3

---

### Step 5: Configure Application

1. **Update File Permissions**
   - Set `uploads/` folder to 755 or 777
   - Set `uploads/profiles/` to 755 or 777
   - Set `pictures/` to 755

2. **Test Database Connection**
   - Create `test_db.php` in root:
   ```php
   <?php
   require_once 'includes/config.php';
   $conn = getDbConnection();
   if ($conn) {
       echo "✅ Database connected successfully!<br>";
       echo "Server: " . DB_HOST . "<br>";
       echo "Database: " . DB_NAME . "<br>";
       $conn->close();
   } else {
       echo "❌ Database connection failed!";
   }
   ?>
   ```
   - Visit: https://yoursite.rf.gd/test_db.php
   - Delete file after testing

3. **Update Absolute Paths**
   - Search and replace any hardcoded paths:
   - `C:\xampp\htdocs\vle_system` → `/home/epizXXXX/htdocs`
   - Or use relative paths (`./../` instead)

4. **Fix Image Paths**
   - Update logo path in navigation if needed
   - Check all image `src` attributes use relative paths

---

### Step 6: Test Your Application

1. **Test Login**
   - Visit: https://yoursite.rf.gd
   - Try logging in with admin credentials
   - Check if redirects work

2. **Test Core Features**
   - ✅ Student registration
   - ✅ Course enrollment
   - ✅ Finance module
   - ✅ File uploads
   - ✅ PDF generation

3. **Check Error Logs**
   - In cPanel → "Error Logs"
   - Fix any PHP errors shown

4. **Test on Mobile**
   - Open site on mobile browser
   - Check responsive design

---

## 🔧 Common Issues & Solutions

### Issue 1: White Screen / Blank Page
**Solution:**
```php
// Add to top of index.php temporarily
error_reporting(E_ALL);
ini_set('display_errors', 1);
```
Check error message, fix issue, then disable error display.

---

### Issue 2: Database Connection Failed
**Check:**
- Database credentials in config.production.php
- Database exists in phpMyAdmin
- User has privileges
- Database host is correct (sqlXXX.infinityfree.net)

---

### Issue 3: Images/CSS Not Loading
**Solution:**
Update paths to relative:
```html
<!-- Wrong -->
<link href="C:/xampp/htdocs/vle_system/assets/css/style.css">

<!-- Correct -->
<link href="assets/css/style.css">
<link href="./assets/css/style.css">
<link href="/assets/css/style.css">
```

---

### Issue 4: Upload Folder Errors
**Solution:**
```bash
# Set permissions via File Manager
uploads/ → 755
uploads/profiles/ → 755
```

Or create `.htaccess` in uploads folder:
```apache
Options -Indexes
<FilesMatch "\.(php|php3|php4|php5|phtml)$">
    Order Deny,Allow
    Deny from all
</FilesMatch>
```

---

### Issue 5: Session Not Working
**Solution:**
```php
// Add to includes/config.php
ini_set('session.save_path', '/tmp');
session_start();
```

---

### Issue 6: Email Not Sending
**InfinityFree blocks mail() function**

**Solution:** Use SMTP instead (e.g., Gmail SMTP)
```php
// Use PHPMailer with SMTP
// See EMAIL_SETUP.md for configuration
```

---

## 🎯 Performance Optimization

### 1. Enable Caching
Create `.htaccess` rules:
```apache
# Browser Caching
<IfModule mod_expires.c>
    ExpiresActive On
    ExpiresByType image/jpg "access plus 1 year"
    ExpiresByType image/jpeg "access plus 1 year"
    ExpiresByType image/gif "access plus 1 year"
    ExpiresByType image/png "access plus 1 year"
    ExpiresByType text/css "access plus 1 month"
    ExpiresByType application/javascript "access plus 1 month"
</IfModule>
```

### 2. Compress Images
- Use TinyPNG or similar tools
- Optimize images before upload

### 3. Minify CSS/JS
- Combine multiple CSS files into one
- Minify JavaScript files

### 4. Use CDN for Libraries
```html
<!-- Instead of local files -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css">
```

---

## 🔒 Security Checklist

Before going live:

- [ ] Change all default passwords
- [ ] Remove test files (test_db.php, phpinfo.php)
- [ ] Disable error display in production
- [ ] Set secure session cookies
- [ ] Validate all user inputs
- [ ] Escape all database outputs
- [ ] Enable HTTPS/SSL
- [ ] Protect config files with .htaccess
- [ ] Set correct file permissions
- [ ] Regular database backups
- [ ] Update PHP version if needed
- [ ] Remove setup.php after initial setup

---

## 📦 Backup Strategy

### Automated Backups
```bash
# Create backup script: backup.php
<?php
// Database backup
$filename = 'backup_' . date('Y-m-d_H-i-s') . '.sql';
$command = "mysqldump --host=" . DB_HOST . " --user=" . DB_USER . 
           " --password=" . DB_PASS . " " . DB_NAME . " > backups/" . $filename;
system($command);

// Files backup (optional)
// Use cPanel backup feature
?>
```

### Manual Backups
- Weekly: Download database from phpMyAdmin
- Monthly: Download all files via FTP
- Store backups in Google Drive/Dropbox

---

## 🌟 Custom Domain Setup (Optional)

If you have a custom domain (e.g., mvustan.com):

1. **Update DNS Records**
   - Login to domain registrar
   - Update nameservers to InfinityFree's:
     - `ns1.byet.org`
     - `ns2.byet.org`

2. **Add Domain in cPanel**
   - Addon Domains → Add your domain
   - Wait 24-48 hours for DNS propagation

3. **Update config.production.php**
   ```php
   define('SITE_URL', 'https://mvustan.com');
   ```

---

## 📊 Monitoring & Maintenance

### Check Regularly:
- Site uptime (use UptimeRobot - free)
- Error logs in cPanel
- Database size (stay within limits)
- Bandwidth usage
- Security updates

### Monthly Tasks:
- Backup database
- Check for broken links
- Update user passwords
- Review access logs
- Clear old session files

---

## 🆘 Support Resources

**InfinityFree:**
- Forum: https://forum.infinityfree.net
- Knowledge Base: https://infinityfree.net/support

**000webhost:**
- Support: https://www.000webhost.com/forum

**General PHP Help:**
- Stack Overflow: https://stackoverflow.com
- PHP Manual: https://www.php.net/manual

---

## 📝 Quick Deployment Checklist

- [ ] Export local database
- [ ] Create config.production.php
- [ ] Update includes/config.php for environment detection
- [ ] Create .htaccess file
- [ ] Compress VLE files to ZIP
- [ ] Sign up for free hosting
- [ ] Create database on hosting
- [ ] Upload files via File Manager or FTP
- [ ] Import database via phpMyAdmin
- [ ] Update config.production.php with actual credentials
- [ ] Set folder permissions (uploads, pictures)
- [ ] Test database connection
- [ ] Test login and core features
- [ ] Remove test files
- [ ] Disable error display
- [ ] Enable HTTPS
- [ ] Setup backups

---

## 🎓 Alternative: Free Hosting with GitHub Pages + Backend

For a completely free solution with better performance:

**Frontend:** GitHub Pages (free, fast)
**Backend API:** Railway.app or Render.com (free tier)
**Database:** FreeMySQLHosting.net or ElephantSQL (PostgreSQL)

This requires converting to API-based architecture (more advanced).

---

**Ready to Deploy?** Start with InfinityFree for the easiest setup!

**Questions?** Check the hosting provider's forum or documentation.

**Good Luck! 🚀**
