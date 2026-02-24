<?php
// config.php - Database configuration for VLE System
// Auto-detects environment (local vs production)

// Environment Detection - Allow local network access and CLI
$is_local = (
    (isset($_SERVER['HTTP_HOST']) && (
        $_SERVER['HTTP_HOST'] === 'localhost' ||
        $_SERVER['HTTP_HOST'] === '127.0.0.1' ||
        strpos($_SERVER['HTTP_HOST'], 'localhost:') === 0 ||
        strpos($_SERVER['HTTP_HOST'], '127.0.0.1:') === 0 ||
        strpos($_SERVER['HTTP_HOST'], '192.168.') === 0 || // Local network
        strpos($_SERVER['HTTP_HOST'], '10.') === 0 ||      // Private network
        strpos($_SERVER['HTTP_HOST'], '172.16.') === 0     // Private network
    )) ||
    php_sapi_name() === 'cli' // Always treat CLI as local
);

if ($is_local) {
    // LOCAL DEVELOPMENT ENVIRONMENT (including LAN access)
    define('DB_HOST', 'localhost');
    define('DB_USER', 'root');
    define('DB_PASS', '');
    define('DB_NAME', 'university_portal');
    
    // Auto-detect the correct site URL based on the actual path
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    $script = dirname($_SERVER['SCRIPT_NAME']);
    define('SITE_URL', $protocol . '://' . $host . $script);
    
    define('APP_ENV', 'development');
    
    // Show errors in development
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    // PRODUCTION ENVIRONMENT
    // Load production configuration
    if (file_exists(__DIR__ . '/config.production.php')) {
        require_once __DIR__ . '/config.production.php';
    } else {
        die('Production configuration file not found. Please create includes/config.production.php');
    }
}

// Common settings for both environments
if (!defined('DB_CHARSET')) {
    define('DB_CHARSET', 'utf8mb4');
}

// System Version
define('VLE_VERSION', '16.0.1');

// Create database connection
function getDbConnection() {
    static $conn = null;

    // Check if connection is null or was closed
    if ($conn === null || !@$conn->ping()) {
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS);

        if ($conn->connect_error) {
            // In production, log error instead of displaying
            if (defined('APP_ENV') && APP_ENV === 'production') {
                error_log("Database connection failed: " . $conn->connect_error);
                die("Database connection error. Please contact administrator.");
            } else {
                die("Connection failed: " . $conn->connect_error);
            }
        }

        // Create database if it doesn't exist (local only)
        if (defined('APP_ENV') && APP_ENV === 'development') {
            $sql = "CREATE DATABASE IF NOT EXISTS " . DB_NAME;
            if ($conn->query($sql) === FALSE) {
                die("Error creating database: " . $conn->error);
            }
        }

        // Select the database
        if (!$conn->select_db(DB_NAME)) {
            if (defined('APP_ENV') && APP_ENV === 'production') {
                error_log("Database selection failed: " . $conn->error);
                die("Database error. Please contact administrator.");
            } else {
                die("Database selection failed: " . $conn->error);
            }
        }

        $conn->set_charset(DB_CHARSET);
    }

    return $conn;
}

// Session timeout settings (15 minutes = 900 seconds)
define('SESSION_TIMEOUT', 900); // 15 minutes in seconds

// Start session if not already started
if (session_status() == PHP_SESSION_NONE) {
    // Set session cookie parameters
    ini_set('session.gc_maxlifetime', SESSION_TIMEOUT);
    ini_set('session.cookie_lifetime', 0); // Cookie expires when browser closes
    
    session_start();
    
    // Check for session timeout (skip on login/logout pages)
    $script_name = basename($_SERVER['SCRIPT_NAME'] ?? '');
    $skip_timeout_check = in_array($script_name, ['login.php', 'login_process.php', 'logout.php']);
    
    if (isset($_SESSION['vle_user_id']) && !$skip_timeout_check) {
        // Check if last activity was set
        if (isset($_SESSION['vle_last_activity'])) {
            $elapsed_time = time() - $_SESSION['vle_last_activity'];
            
            // If inactive for more than SESSION_TIMEOUT, destroy session
            if ($elapsed_time > SESSION_TIMEOUT) {
                session_unset();
                session_destroy();
                
                // Redirect to login with timeout message
                if (!defined('LOGIN_PAGE')) {
                    header('Location: ' . (defined('SITE_URL') ? SITE_URL : '') . '/login.php?timeout=1');
                    exit();
                }
            }
        }
        
        // Update last activity time
        $_SESSION['vle_last_activity'] = time();
    }
}
?>