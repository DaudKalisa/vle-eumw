<?php
/**
 * Production Database Configuration
 * Update these values with your actual hosting credentials
 */

// Database Configuration - Production
define('DB_HOST', 'localhost');
define('DB_USER', 'u615976264_vleadmin');
define('DB_PASS', 'Vle-admin2026');
define('DB_NAME', 'u615976264_portal');
define('DB_CHARSET', 'utf8mb4');

// Site Configuration
define('SITE_URL', 'https://vle.exploitsonline.com'); // Production site URL

// Security Settings for Production
error_reporting(0);                             // Disable error display
ini_set('display_errors', 0);                  // Hide errors from users
ini_set('log_errors', 1);                      // Log errors to file
ini_set('error_log', __DIR__ . '/../error.log'); // Error log location

// Session Security
ini_set('session.cookie_httponly', 1);         // Prevent JavaScript access to cookies
ini_set('session.use_only_cookies', 1);        // Use only cookies for sessions
ini_set('session.cookie_secure', 1);           // Require HTTPS for cookies (if you have SSL)

// File Upload Settings
ini_set('upload_max_filesize', '10M');
ini_set('post_max_size', '10M');

// Timezone
date_default_timezone_set('Africa/Blantyre');  // Malawi timezone

// Application Settings
define('APP_NAME', 'Exploits University Malawi VLE');
define('APP_ENV', 'production');

?>
