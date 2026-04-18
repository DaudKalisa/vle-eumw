<?php
/**
 * Standard HTML Head for VLE Pages
 * Ensures consistency across all pages
 * 
 * Usage in page <head>:
 *   <?php include '../includes/standard-head.php'; ?>
 *   <title>Page Title</title>
 *   <!-- Additional page-specific styles here -->
 */

// Get theme
$current_theme = $_SESSION['vle_theme'] ?? 'navy';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    
    <!-- Bootstrap 5.1.3 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Bootstrap Icons 1.10.0 (Latest) -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Global VLE Theme -->
    <link href="<?= isset($root_path) ? $root_path : '' ?>assets/css/global-theme.css" rel="stylesheet">
    
    <!-- PWA Support -->
    <link rel="manifest" href="<?= isset($root_path) ? $root_path : '' ?>manifest.json">
    <meta name="theme-color" content="#4f46e5">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    
    <!-- Page Title - Override in page -->
    <!-- <title>Page Title - VLE System</title> -->
</head>
<body>
