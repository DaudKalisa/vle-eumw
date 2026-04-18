<?php
/**
 * PWA Head Tags
 * Include this in the <head> section of any page to enable PWA support.
 * Usage: <?php include_once __DIR__ . '/../includes/pwa-head.php'; ?> (from subfolder)
 *        <?php include_once __DIR__ . '/includes/pwa-head.php'; ?>   (from root)
 * 
 * Determines the correct relative path to root automatically.
 */

// Determine relative path to root based on script location
$_pwa_script = $_SERVER['PHP_SELF'] ?? '';
$_pwa_depth = substr_count(trim(dirname($_pwa_script), '/'), '/');
$_pwa_root = $_pwa_depth > 0 ? str_repeat('../', $_pwa_depth) : './';
?>
<!-- PWA Support -->
<link rel="manifest" href="<?= $_pwa_root ?>manifest.json">
<meta name="theme-color" content="#0d1b4a">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="EUMW VLE">
<link rel="apple-touch-icon" href="<?= $_pwa_root ?>assets/icons/icon-192.png">
<meta name="mobile-web-app-capable" content="yes">
<meta name="application-name" content="EUMW VLE">
<meta name="msapplication-TileImage" content="<?= $_pwa_root ?>assets/icons/icon-144.png">
<meta name="msapplication-TileColor" content="#0d1b4a">
