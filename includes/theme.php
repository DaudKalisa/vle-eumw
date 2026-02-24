<?php
/**
 * Theme Handler - Set/Get User Theme Preference
 * Stores theme in session and optionally in database
 */

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Available themes
$available_themes = ['navy', 'emerald', 'purple', 'orange'];

// Handle theme change via AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['theme'])) {
    $theme = $_POST['theme'];
    
    if (in_array($theme, $available_themes)) {
        $_SESSION['vle_theme'] = $theme;
        
        // If user is logged in, save to database
        if (isset($_SESSION['vle_user_id'])) {
            require_once __DIR__ . '/config.php';
            $conn = getDbConnection();
            
            // Check if theme_preference column exists in users table
            $result = $conn->query("SHOW COLUMNS FROM users LIKE 'theme_preference'");
            if ($result->num_rows > 0) {
                $stmt = $conn->prepare("UPDATE users SET theme_preference = ? WHERE user_id = ?");
                $stmt->bind_param("si", $theme, $_SESSION['vle_user_id']);
                $stmt->execute();
            }
                    }
        
        echo json_encode(['success' => true, 'theme' => $theme]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Invalid theme']);
    }
    exit;
}

/**
 * Get the current theme for the user
 */
function getCurrentTheme() {
    global $available_themes;
    
    // Check session first
    if (isset($_SESSION['vle_theme']) && in_array($_SESSION['vle_theme'], $available_themes)) {
        return $_SESSION['vle_theme'];
    }
    
    // Default to navy
    return 'navy';
}

/**
 * Load theme from database for logged-in user
 */
function loadUserTheme() {
    global $available_themes;
    
    if (isset($_SESSION['vle_user_id']) && !isset($_SESSION['vle_theme'])) {
        require_once __DIR__ . '/config.php';
        $conn = getDbConnection();
        
        // Check if theme_preference column exists
        $result = $conn->query("SHOW COLUMNS FROM users LIKE 'theme_preference'");
        if ($result->num_rows > 0) {
            $stmt = $conn->prepare("SELECT theme_preference FROM users WHERE user_id = ?");
            $stmt->bind_param("i", $_SESSION['vle_user_id']);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($row = $result->fetch_assoc()) {
                if ($row['theme_preference'] && in_array($row['theme_preference'], $available_themes)) {
                    $_SESSION['vle_theme'] = $row['theme_preference'];
                }
            }
        }
            }
    
    return getCurrentTheme();
}
?>
